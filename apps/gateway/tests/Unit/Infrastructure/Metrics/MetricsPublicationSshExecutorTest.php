<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsPublicationSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('publishes and verifies the exact Gateway-only Grafana firewall rule', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: "Status: active\n"),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    $publication->converge(metricsPublicationNode('metrics', '10.44.0.3'), '10.44.0.1');

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
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
            '10.44.0.1',
            'to',
            '10.44.0.3',
            'port',
            '3000',
            'comment',
            'orbit:metrics-grafana-upstream',
        ],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

it('refuses foreign Grafana firewall ownership before mutation', function (): void {
    $foreign = str_replace('10.44.0.1', '10.44.0.4', metricsPublicationFirewallStatus());
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: $foreign),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    expect(fn () => $publication->converge(metricsPublicationNode('metrics', '10.44.0.3'), '10.44.0.1'))
        ->toThrow(ResourceOperationException::class, 'ownership cannot be proved')
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('removes only the proven Grafana firewall rule and verifies absence', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: "Status: active\n"),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    $publication->remove(metricsPublicationNode('metrics', '10.44.0.3'), '10.44.0.1');

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        ['sudo', 'ufw', '--force', 'delete', '7'],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

it('abandons the single commented Grafana firewall rule without a Gateway address', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: "Status: active\n"),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    $publication->abandon(metricsPublicationNode('metrics', '10.44.0.3'));

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        ['sudo', 'ufw', '--force', 'delete', '7'],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

it('does nothing when abandoning with no commented Grafana firewall rule present', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: "Status: active\n"),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    $publication->abandon(metricsPublicationNode('metrics', '10.44.0.3'));

    expect($ssh->commands)->toHaveCount(1);
});

it('fails closed when an abandoned Grafana firewall rule survives removal', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    try {
        $publication->abandon(metricsPublicationNode('metrics', '10.44.0.3'));
        test()->fail('Expected abandon to fail closed when the rule survives removal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('metrics.publication_firewall_remove_verify_failed');
    }
});

it('leaves a neighbouring rule whose comment only starts with the Orbit marker', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationNeighbourFirewallStatus()),
    ]);
    $publication = metricsPublicationSshExecutor($ssh);

    $publication->abandon(metricsPublicationNode('metrics', '10.44.0.3'));

    expect($ssh->commands)->toHaveCount(1);
});

function metricsPublicationSshExecutor(
    MetricsPublicationCapturingSshExecutor $ssh,
): MetricsPublicationSshExecutor {
    return new MetricsPublicationSshExecutor(
        ssh: $ssh,
        keys: new MetricsPublicationSshKeyProviderFake,
        knownHosts: new MetricsPublicationKnownHostsStoreFake,
    );
}

function metricsPublicationNode(string $name, string $address): Node
{
    return new Node([
        'name' => $name,
        'wireguard_ip' => $address,
    ]);
}

function metricsPublicationResult(int $exitCode = 0, string $stdout = ''): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 1, false);
}

function metricsPublicationFirewallStatus(): string
{
    return <<<'STATUS'
        Status: active

        [ 7] 10.44.0.3 3000/tcp on orbit ALLOW IN 10.44.0.1 # orbit:metrics-grafana-upstream
        STATUS;
}

final class MetricsPublicationCapturingSshExecutor implements SshExecutor
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

        return array_shift($this->results) ?? metricsPublicationResult();
    }
}

final readonly class MetricsPublicationSshKeyProviderFake implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/metrics-publication-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 metrics-publication-key';
    }
}

final readonly class MetricsPublicationKnownHostsStoreFake implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/metrics-publication-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

function metricsPublicationNeighbourFirewallStatus(): string
{
    return <<<'STATUS'
        Status: active

        [ 9] 10.44.0.3 3000/tcp on orbit ALLOW IN 10.44.0.1 # orbit:metrics-grafana-upstream-v2
        STATUS;
}
