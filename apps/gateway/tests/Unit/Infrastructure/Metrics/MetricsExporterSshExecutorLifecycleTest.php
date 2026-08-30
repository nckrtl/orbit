<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsExporterSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('converges protected exporter configuration and exact Metrics-owned firewall access', function (): void {
    $ssh = new MetricsExporterCapturingSsh([
        metricsExporterResult(exitCode: 1),
        metricsExporterResult(stdout: "Status: active\n"),
        metricsExporterResult(exitCode: 3, stdout: "inactive\n"),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(stdout: metricsExporterConfiguration('10.44.0.4')),
        metricsExporterResult(stdout: "active\n"),
        metricsExporterResult(stdout: metricsExporterFirewallStatus('10.44.0.4')),
    ]);
    $executor = metricsExporterExecutor($ssh);

    $executor->converge(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    );

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))
        ->toContain(
            ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', 'prometheus-node-exporter'],
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'restart', 'prometheus-node-exporter'],
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'orbit',
                'proto',
                'tcp',
                'from',
                '10.44.0.3',
                'to',
                '10.44.0.4',
                'port',
                '9100',
                'comment',
                'orbit:metrics-node-exporter',
            ],
        )
        ->and($ssh->commands[5]->protectedInput)
        ->not->toBeNull()->and(var_export($ssh->commands[5], true))
        ->not->toContain('10.44.0.4');
});

it('stages the exporter drop-in beside its target and moves it into place', function (): void {
    $ssh = new MetricsExporterStatefulSsh(
        configuration: metricsExporterConfiguration('10.44.0.4'),
        serviceActive: true,
        firewall: true,
    );
    $executor = metricsExporterExecutor($ssh);

    $executor->converge(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    );

    $arguments = array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    );
    $target = '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf';

    expect($arguments)
        ->toContain(
            ['sudo', 'rm', '-f', '--', $target.'.orbit-candidate'],
            [
                'sudo',
                'install',
                '-D',
                '-o',
                'root',
                '-g',
                'root',
                '-m',
                '0644',
                '/dev/stdin',
                $target.'.orbit-candidate',
            ],
            ['sudo', 'mv', '-fT', '--', $target.'.orbit-candidate', $target],
        )
        ->and($arguments)
        ->not
        ->toContain(
            ['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', $target],
        )
        ->and($ssh->configuration)
        ->toBe(metricsExporterConfiguration('10.44.0.4'));
});

it('refuses foreign exporter configuration before any mutation', function (): void {
    $ssh = new MetricsExporterCapturingSsh([
        metricsExporterResult(),
        metricsExporterResult(stdout: "[Service]\nExecStart=/usr/bin/prometheus-node-exporter\n"),
    ]);
    $executor = metricsExporterExecutor($ssh);

    expect(fn () => $executor->converge(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class, 'ownership cannot be proved')
        ->and($ssh->commands)
        ->toHaveCount(2);
});

it('removes only proven exporter configuration and firewall state', function (): void {
    $ssh = new MetricsExporterCapturingSsh([
        metricsExporterResult(),
        metricsExporterResult(stdout: metricsExporterConfiguration('10.44.0.4')),
        metricsExporterResult(stdout: metricsExporterFirewallStatus('10.44.0.4')),
        metricsExporterResult(stdout: "active\n"),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(),
        metricsExporterResult(exitCode: 1),
        metricsExporterResult(exitCode: 3, stdout: "inactive\n"),
        metricsExporterResult(stdout: "Status: active\n"),
    ]);
    $executor = metricsExporterExecutor($ssh);

    $executor->remove(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    );

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toContain(
        ['sudo', 'systemctl', 'disable', '--now', 'prometheus-node-exporter'],
        ['sudo', 'rm', '-f', '--', '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf'],
        ['sudo', 'ufw', '--force', 'delete', '5'],
    );
});

it('restores absent exporter state when convergence verification fails', function (): void {
    $ssh = new MetricsExporterStatefulSsh(
        configuration: null,
        serviceActive: false,
        firewall: false,
        failArguments: ['sudo', 'ufw', 'status', 'numbered'],
        failOccurrence: 2,
    );
    $executor = metricsExporterExecutor($ssh);

    expect(fn () => $executor->converge(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class);

    expect($ssh->configuration)
        ->toBeNull()
        ->and($ssh->serviceActive)
        ->toBeFalse()
        ->and($ssh->firewall)
        ->toBeFalse();
});

it('restores active exporter state when removal fails after service disablement', function (): void {
    $configuration = metricsExporterConfiguration('10.44.0.4');
    $ssh = new MetricsExporterStatefulSsh(
        configuration: $configuration,
        serviceActive: true,
        firewall: true,
        failArguments: ['sudo', 'rm', '-f', '--', '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf'],
    );
    $executor = metricsExporterExecutor($ssh);

    expect(fn () => $executor->remove(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class);

    expect($ssh->configuration)
        ->toBe($configuration)
        ->and($ssh->serviceActive)
        ->toBeTrue()
        ->and($ssh->firewall)
        ->toBeTrue();
});

it('fails removal when the exporter service remains active', function (): void {
    $configuration = metricsExporterConfiguration('10.44.0.4');
    $ssh = new MetricsExporterStatefulSsh(
        configuration: $configuration,
        serviceActive: true,
        firewall: true,
        disableChangesState: false,
    );
    $executor = metricsExporterExecutor($ssh);

    expect(fn () => $executor->remove(
        metricsExporterNode('app-prod', '10.44.0.4'),
        metricsExporterNode('metrics', '10.44.0.3'),
    ))
        ->toThrow(ResourceOperationException::class, 'service remained active');
});

function metricsExporterExecutor(SshExecutor $ssh): MetricsExporterSshExecutor
{
    return new MetricsExporterSshExecutor(
        ssh: $ssh,
        keys: new MetricsExporterSshKeyProviderFake,
        knownHosts: new MetricsExporterKnownHostsStoreFake,
    );
}

function metricsExporterNode(string $name, string $address): Node
{
    return new Node([
        'name' => $name,
        'wireguard_address' => $address,
    ]);
}

function metricsExporterResult(int $exitCode = 0, string $stdout = ''): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 1, false);
}

function metricsExporterConfiguration(string $address): string
{
    return "# Managed by Orbit: metrics\n[Service]\nExecStart=\nExecStart=/usr/bin/prometheus-node-exporter --web.listen-address={$address}:9100\n";
}

function metricsExporterFirewallStatus(string $destination): string
{
    return <<<STATUS
        Status: active

        [ 5] {$destination} 9100/tcp on orbit ALLOW IN 10.44.0.3 # orbit:metrics-node-exporter
        STATUS;
}

final class MetricsExporterCapturingSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return array_shift($this->results) ?? metricsExporterResult();
    }
}

final class MetricsExporterStatefulSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var array<string, int> */
    private array $occurrences = [];

    private ?string $candidate = null;

    /**
     * @param list<string>|null $failArguments
     * @mago-expect lint:excessive-parameter-list The stateful fake exposes every exporter recovery dimension under test.
     */
    public function __construct(
        public ?string $configuration,
        public bool $serviceActive,
        public bool $firewall,
        private ?array $failArguments = null,
        private int $failOccurrence = 1,
        private bool $disableChangesState = true,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $key = implode("\0", $command->arguments);
        $occurrence = ($this->occurrences[$key] ?? 0) + 1;
        $this->occurrences[$key] = $occurrence;

        if ($command->arguments === $this->failArguments && $occurrence === $this->failOccurrence) {
            return metricsExporterResult(exitCode: 1);
        }

        return match ($command->arguments) {
            ['sudo', 'test', '-e', '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf']
                => metricsExporterResult(
                exitCode: $this->configuration === null ? 1 : 0,
            ),
            ['sudo', 'cat', '--', '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf']
                => metricsExporterResult(
                stdout: $this->configuration ?? '',
            ),
            ['sudo', 'ufw', 'status', 'numbered'] => metricsExporterResult(
                stdout: $this->firewall
                    ? metricsExporterFirewallStatus($connection->host)
                    : "Status: active\n",
            ),
            ['sudo', 'apt-get', 'install', '--yes', '--no-install-recommends', '--', 'prometheus-node-exporter']
                => metricsExporterResult(),
            [
                'sudo',
                'install',
                '-D',
                '-o',
                'root',
                '-g',
                'root',
                '-m',
                '0644',
                '/dev/stdin',
                '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf.orbit-candidate',
            ]
                => $this->stage($command),
            [
                'sudo',
                'rm',
                '-f',
                '--',
                '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf.orbit-candidate',
            ]
                => $this->discardCandidate(),
            [
                'sudo',
                'mv',
                '-fT',
                '--',
                '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf.orbit-candidate',
                '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf',
            ]
                => $this->publishCandidate(),
            ['sudo', 'systemctl', 'enable', '--now', 'prometheus-node-exporter'] => $this->enable(),
            ['sudo', 'systemctl', 'restart', 'prometheus-node-exporter'] => $this->enable(),
            ['sudo', 'systemctl', 'daemon-reload'] => metricsExporterResult(),
            ['sudo', 'systemctl', 'disable', '--now', 'prometheus-node-exporter'] => $this->disable(),
            ['sudo', 'rm', '-f', '--', '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf']
                => $this->removeConfiguration(),
            ['systemctl', 'is-active', 'prometheus-node-exporter'] => metricsExporterResult(
                exitCode: $this->serviceActive ? 0 : 3,
                stdout: $this->serviceActive ? "active\n" : "inactive\n",
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'orbit',
                'proto',
                'tcp',
                'from',
                '10.44.0.3',
                'to',
                '10.44.0.4',
                'port',
                '9100',
                'comment',
                'orbit:metrics-node-exporter',
            ]
                => $this->addFirewall(),
            ['sudo', 'ufw', '--force', 'delete', '5'] => $this->removeFirewall(),
            default => metricsExporterResult(),
        };
    }

    private function stage(RemoteCommand $command): CommandResult
    {
        $this->candidate = stream_get_contents($command->protectedInput?->stream()) ?: '';

        return metricsExporterResult();
    }

    private function discardCandidate(): CommandResult
    {
        $this->candidate = null;

        return metricsExporterResult();
    }

    private function publishCandidate(): CommandResult
    {
        if ($this->candidate === null) {
            return metricsExporterResult(exitCode: 1);
        }

        $this->configuration = $this->candidate;
        $this->candidate = null;

        return metricsExporterResult();
    }

    private function enable(): CommandResult
    {
        $this->serviceActive = true;

        return metricsExporterResult();
    }

    private function disable(): CommandResult
    {
        if ($this->disableChangesState) {
            $this->serviceActive = false;
        }

        return metricsExporterResult();
    }

    private function removeConfiguration(): CommandResult
    {
        $this->configuration = null;

        return metricsExporterResult();
    }

    private function addFirewall(): CommandResult
    {
        $this->firewall = true;

        return metricsExporterResult();
    }

    private function removeFirewall(): CommandResult
    {
        $this->firewall = false;

        return metricsExporterResult();
    }
}

final readonly class MetricsExporterSshKeyProviderFake implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/metrics-exporter-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 metrics-exporter-key';
    }
}

final readonly class MetricsExporterKnownHostsStoreFake implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/metrics-exporter-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
