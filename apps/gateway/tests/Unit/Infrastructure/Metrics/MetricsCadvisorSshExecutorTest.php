<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsCadvisorSshExecutor;
use App\Infrastructure\Metrics\MetricsFootprint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('installs the pinned binary, unit, and exact firewall rule on first convergence', function (): void {
    $ssh = new CadvisorStatefulSsh(
        binaryChecksum: null,
        configuration: null,
        serviceActive: false,
        firewall: false,
    );
    $executor = cadvisorExecutor($ssh);

    $executor->converge(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    );

    $arguments = array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    );

    expect($arguments)
        ->toContain(
            [
                'sudo', 'curl', '--fail', '--location', '--silent', '--show-error',
                '--output', '/usr/local/bin/orbit-cadvisor.orbit-candidate', '--', MetricsFootprint::CadvisorDownloadUrl,
            ],
            ['sudo', 'chown', 'root:root', '--', '/usr/local/bin/orbit-cadvisor.orbit-candidate'],
            ['sudo', 'chmod', '0755', '--', '/usr/local/bin/orbit-cadvisor.orbit-candidate'],
            [
                'sudo', 'mv', '-fT', '--',
                '/usr/local/bin/orbit-cadvisor.orbit-candidate', '/usr/local/bin/orbit-cadvisor',
            ],
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'enable', '--now', 'orbit-cadvisor'],
            ['sudo', 'systemctl', 'restart', 'orbit-cadvisor'],
            [
                'sudo', 'ufw', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp',
                'from', '10.44.0.3', 'to', '10.44.0.4', 'port', '9102', 'comment', 'orbit:metrics-cadvisor',
            ],
        );

    expect($ssh->configuration)
        ->toStartWith(MetricsFootprint::CadvisorUnitMarker."\n")
        ->and($ssh->configuration)
        ->toContain('--listen_ip=10.44.0.4')
        ->toContain('--port=9102')
        ->toContain('--store_container_labels=false')
        ->toContain('--docker_only=false')
        ->toContain('--disable_metrics=');

    foreach (MetricsFootprint::CadvisorDisabledMetrics as $metric) {
        expect($ssh->configuration)->toContain($metric);
    }

    expect(MetricsFootprint::CadvisorDisabledMetrics)
        ->not->toContain('cpu')
        ->not->toContain('memory');
});

it('skips the download when the installed binary already matches the pinned checksum', function (): void {
    $ssh = new CadvisorStatefulSsh(
        binaryChecksum: MetricsFootprint::CadvisorChecksumSha256,
        configuration: cadvisorUnit('10.44.0.4'),
        serviceActive: true,
        firewall: true,
    );
    $executor = cadvisorExecutor($ssh);

    $executor->converge(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    );

    $arguments = array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    );

    expect($arguments)->not->toContain([
        'sudo', 'curl', '--fail', '--location', '--silent', '--show-error',
        '--output', '/usr/local/bin/orbit-cadvisor.orbit-candidate', '--', MetricsFootprint::CadvisorDownloadUrl,
    ]);
});

it('fails the converge loudly and never installs a binary that fails checksum verification', function (): void {
    $ssh = new CadvisorCapturingSsh([
        cadvisorResult(exitCode: 1), // [snapshot] configuration test -e: absent
        cadvisorResult(stdout: "Status: active\n"), // [snapshot] ufw status
        cadvisorResult(exitCode: 3, stdout: "inactive\n"), // [snapshot] systemctl is-active
        cadvisorResult(exitCode: 1), // [installBinary] existing binary sha256sum: absent
        cadvisorResult(), // [installBinary] rm candidate
        cadvisorResult(), // [installBinary] curl download
        cadvisorResult(stdout: "0000000000000000000000000000000000000000000000000000000000000000  /usr/local/bin/orbit-cadvisor.orbit-candidate\n"), // [installBinary] wrong checksum
        cadvisorResult(), // [installBinary] rm candidate cleanup after mismatch — throws from here
        cadvisorResult(exitCode: 1), // [restore] configuration test -e
        cadvisorResult(exitCode: 3, stdout: "inactive\n"), // [restore] systemctl is-active
        cadvisorResult(stdout: "Status: active\n"), // [restore] ufw status
        cadvisorResult(exitCode: 1), // [verifyRestoredState] configuration test -e
        cadvisorResult(exitCode: 3, stdout: "inactive\n"), // [verifyRestoredState] systemctl is-active
        cadvisorResult(stdout: "Status: active\n"), // [verifyRestoredState] ufw status
    ]);
    $executor = cadvisorExecutor($ssh);

    expect(fn () => $executor->converge(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    ))->toThrow(ResourceOperationException::class, 'checksum verification');

    $arguments = array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    );

    expect($arguments)->not->toContain([
        'sudo', 'mv', '-fT', '--',
        '/usr/local/bin/orbit-cadvisor.orbit-candidate', '/usr/local/bin/orbit-cadvisor',
    ]);
});

it('removes the unit, service, firewall rule, and binary, in that order', function (): void {
    $ssh = new CadvisorStatefulSsh(
        binaryChecksum: MetricsFootprint::CadvisorChecksumSha256,
        configuration: cadvisorUnit('10.44.0.4'),
        serviceActive: true,
        firewall: true,
    );
    $executor = cadvisorExecutor($ssh);

    $executor->remove(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    );

    $arguments = array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    );

    expect($arguments)->toContain(
        ['sudo', 'systemctl', 'disable', '--now', 'orbit-cadvisor'],
        ['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-cadvisor.service'],
        ['sudo', 'ufw', '--force', 'delete', '5'],
        ['sudo', 'rm', '-f', '--', '/usr/local/bin/orbit-cadvisor'],
    );

    $removeIndex = array_search(['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-cadvisor.service'], $arguments, true);
    $binaryIndex = array_search(['sudo', 'rm', '-f', '--', '/usr/local/bin/orbit-cadvisor'], $arguments, true);

    expect(is_int($removeIndex) && is_int($binaryIndex) && $removeIndex < $binaryIndex)->toBeTrue();
    expect(array_slice($arguments, (int) $removeIndex, 3))->toBe([
        ['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-cadvisor.service'],
        ['sudo', 'systemctl', 'daemon-reload'],
        ['sudo', 'systemctl', 'reset-failed', 'orbit-cadvisor'],
    ]);
    expect($ssh->configuration)->toBeNull()->and($ssh->serviceActive)->toBeFalse()->and($ssh->firewall)->toBeFalse();
});

it('completes removal when the cAdvisor unit has no failed record to reset', function (): void {
    $ssh = new CadvisorStatefulSsh(
        binaryChecksum: MetricsFootprint::CadvisorChecksumSha256,
        configuration: cadvisorUnit('10.44.0.4'),
        serviceActive: true,
        firewall: true,
        failArguments: ['sudo', 'systemctl', 'reset-failed', 'orbit-cadvisor'],
    );

    cadvisorExecutor($ssh)->remove(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    );

    expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->toContain(['sudo', 'systemctl', 'reset-failed', 'orbit-cadvisor'], ['sudo', 'rm', '-f', '--', '/usr/local/bin/orbit-cadvisor'])
        ->and($ssh->configuration)->toBeNull()
        ->and($ssh->firewall)->toBeFalse();
});

it('refuses foreign unit ownership before any mutation', function (): void {
    $ssh = new CadvisorCapturingSsh([
        cadvisorResult(),
        cadvisorResult(stdout: "[Unit]\nDescription=someone else's cadvisor\n"),
    ]);
    $executor = cadvisorExecutor($ssh);

    expect(fn () => $executor->converge(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class, 'ownership cannot be proved')
        ->and($ssh->commands)
        ->toHaveCount(2);
});

it('restores the prior unit and service state when a convergence step fails', function (): void {
    $ssh = new CadvisorStatefulSsh(
        binaryChecksum: MetricsFootprint::CadvisorChecksumSha256,
        configuration: null,
        serviceActive: false,
        firewall: false,
        failArguments: ['sudo', 'ufw', 'status', 'numbered'],
        failOccurrence: 2,
    );
    $executor = cadvisorExecutor($ssh);

    expect(fn () => $executor->converge(
        cadvisorNode('app-prod', '10.44.0.4'),
        cadvisorNode('metrics', '10.44.0.3'),
    ))->toThrow(ResourceOperationException::class);

    expect($ssh->configuration)->toBeNull()->and($ssh->serviceActive)->toBeFalse()->and($ssh->firewall)->toBeFalse();
});

function cadvisorExecutor(SshExecutor $ssh): MetricsCadvisorSshExecutor
{
    return new MetricsCadvisorSshExecutor(
        ssh: $ssh,
        keys: new CadvisorSshKeyProviderFake,
        knownHosts: new CadvisorKnownHostsStoreFake,
    );
}

function cadvisorNode(string $name, string $address, string $user = 'orbit'): Node
{
    return new Node([
        'name' => $name,
        'wireguard_ip' => $address,
        'user' => $user,
    ]);
}

function cadvisorResult(int $exitCode = 0, string $stdout = ''): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 1, false);
}

function cadvisorUnit(string $address): string
{
    return MetricsFootprint::CadvisorUnitMarker
        ."\n[Unit]\nDescription=Orbit cAdvisor exporter\nAfter=network-online.target\nWants=network-online.target\n\n"
        ."[Service]\nExecStart=/usr/local/bin/orbit-cadvisor --listen_ip={$address} --port=9102 --store_container_labels=false --docker_only=false --disable_metrics=".implode(',', MetricsFootprint::CadvisorDisabledMetrics)
        ."\nRestart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n";
}

function cadvisorFirewallStatus(string $destination): string
{
    return <<<STATUS
        Status: active

        [ 5] {$destination} 9102/tcp on orbit ALLOW IN 10.44.0.3 # orbit:metrics-cadvisor
        STATUS;
}

final class CadvisorCapturingSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(private array $results) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return array_shift($this->results) ?? cadvisorResult();
    }
}

final class CadvisorStatefulSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var array<string, int> */
    private array $occurrences = [];

    private ?string $candidate = null;

    /** @param list<string>|null $failArguments */
    public function __construct(
        public ?string $binaryChecksum,
        public ?string $configuration,
        public bool $serviceActive,
        public bool $firewall,
        private ?array $failArguments = null,
        private int $failOccurrence = 1,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $key = implode("\0", $command->arguments);
        $occurrence = ($this->occurrences[$key] ?? 0) + 1;
        $this->occurrences[$key] = $occurrence;

        if ($command->arguments === $this->failArguments && $occurrence === $this->failOccurrence) {
            return cadvisorResult(exitCode: 1);
        }

        return match ($command->arguments) {
            ['sudo', 'test', '-e', '/etc/systemd/system/orbit-cadvisor.service'] => cadvisorResult(
                exitCode: $this->configuration === null ? 1 : 0,
            ),
            ['sudo', 'cat', '--', '/etc/systemd/system/orbit-cadvisor.service'] => cadvisorResult(
                stdout: $this->configuration ?? '',
            ),
            ['sudo', 'ufw', 'status', 'numbered'] => cadvisorResult(
                stdout: $this->firewall ? cadvisorFirewallStatus($connection->host) : "Status: active\n",
            ),
            ['sudo', 'sha256sum', '--', '/usr/local/bin/orbit-cadvisor'] => $this->binaryChecksum === null
                ? cadvisorResult(exitCode: 1)
                : cadvisorResult(stdout: "{$this->binaryChecksum}  /usr/local/bin/orbit-cadvisor\n"),
            ['sudo', 'rm', '-f', '--', '/usr/local/bin/orbit-cadvisor.orbit-candidate'] => cadvisorResult(),
            [
                'sudo', 'curl', '--fail', '--location', '--silent', '--show-error',
                '--output', '/usr/local/bin/orbit-cadvisor.orbit-candidate', '--', MetricsFootprint::CadvisorDownloadUrl,
            ] => cadvisorResult(),
            ['sudo', 'sha256sum', '--', '/usr/local/bin/orbit-cadvisor.orbit-candidate'] => cadvisorResult(
                stdout: MetricsFootprint::CadvisorChecksumSha256.'  /usr/local/bin/orbit-cadvisor.orbit-candidate'."\n",
            ),
            ['sudo', 'chown', 'root:root', '--', '/usr/local/bin/orbit-cadvisor.orbit-candidate'] => cadvisorResult(),
            ['sudo', 'chmod', '0755', '--', '/usr/local/bin/orbit-cadvisor.orbit-candidate'] => cadvisorResult(),
            [
                'sudo', 'mv', '-fT', '--',
                '/usr/local/bin/orbit-cadvisor.orbit-candidate', '/usr/local/bin/orbit-cadvisor',
            ] => $this->installBinary(),
            [
                'sudo', 'rm', '-f', '--',
                '/etc/systemd/system/orbit-cadvisor.service.orbit-candidate',
            ] => $this->discardCandidate(),
            [
                'sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin',
                '/etc/systemd/system/orbit-cadvisor.service.orbit-candidate',
            ] => $this->stage($command),
            [
                'sudo', 'mv', '-fT', '--',
                '/etc/systemd/system/orbit-cadvisor.service.orbit-candidate', '/etc/systemd/system/orbit-cadvisor.service',
            ] => $this->publishConfiguration(),
            ['sudo', 'systemctl', 'enable', '--now', 'orbit-cadvisor'] => $this->enable(),
            ['sudo', 'systemctl', 'restart', 'orbit-cadvisor'] => $this->enable(),
            ['sudo', 'systemctl', 'daemon-reload'] => cadvisorResult(),
            ['sudo', 'systemctl', 'disable', '--now', 'orbit-cadvisor'] => $this->disable(),
            ['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-cadvisor.service'] => $this->removeConfiguration(),
            ['systemctl', 'is-active', 'orbit-cadvisor'] => cadvisorResult(
                exitCode: $this->serviceActive ? 0 : 3,
                stdout: $this->serviceActive ? "active\n" : "inactive\n",
            ),
            [
                'sudo', 'ufw', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp',
                'from', '10.44.0.3', 'to', '10.44.0.4', 'port', '9102', 'comment', 'orbit:metrics-cadvisor',
            ] => $this->addFirewall(),
            ['sudo', 'ufw', '--force', 'delete', '5'] => $this->removeFirewall(),
            ['sudo', 'rm', '-f', '--', '/usr/local/bin/orbit-cadvisor'] => cadvisorResult(),
            default => cadvisorResult(),
        };
    }

    private function installBinary(): CommandResult
    {
        $this->binaryChecksum = MetricsFootprint::CadvisorChecksumSha256;

        return cadvisorResult();
    }

    private function stage(RemoteCommand $command): CommandResult
    {
        $this->candidate = stream_get_contents($command->protectedInput?->stream()) ?: '';

        return cadvisorResult();
    }

    private function discardCandidate(): CommandResult
    {
        $this->candidate = null;

        return cadvisorResult();
    }

    private function publishConfiguration(): CommandResult
    {
        if ($this->candidate === null) {
            return cadvisorResult(exitCode: 1);
        }

        $this->configuration = $this->candidate;
        $this->candidate = null;

        return cadvisorResult();
    }

    private function enable(): CommandResult
    {
        $this->serviceActive = true;

        return cadvisorResult();
    }

    private function disable(): CommandResult
    {
        $this->serviceActive = false;

        return cadvisorResult();
    }

    private function removeConfiguration(): CommandResult
    {
        $this->configuration = null;

        return cadvisorResult();
    }

    private function addFirewall(): CommandResult
    {
        $this->firewall = true;

        return cadvisorResult();
    }

    private function removeFirewall(): CommandResult
    {
        $this->firewall = false;

        return cadvisorResult();
    }
}

final readonly class CadvisorSshKeyProviderFake implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/cadvisor-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 cadvisor-key';
    }
}

final readonly class CadvisorKnownHostsStoreFake implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/cadvisor-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
