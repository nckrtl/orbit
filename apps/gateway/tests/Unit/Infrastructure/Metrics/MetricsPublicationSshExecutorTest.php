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

it('uses the configured Metrics node user for every SSH connection', function (string $user): void {
    $ssh = new MetricsPublicationCapturingSshExecutor(metricsPublicationConvergenceResults());

    metricsPublicationSshExecutor($ssh)->converge(
        metricsPublicationNode('metrics', '10.44.0.3', $user),
        '10.44.0.1',
    );

    expect(array_map(
        static fn (SshConnection $connection): string => $connection->user,
        $ssh->connections,
    ))->each->toBe($user);
})->with([
    'non-orbit user' => 'deployer',
    'orbit user' => 'orbit',
]);

it('does not fall back when the configured Metrics node user cannot authenticate', function (string $user): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(exitCode: 255),
    ]);

    expect(fn () => metricsPublicationSshExecutor($ssh)->converge(
        metricsPublicationNode('metrics', '10.44.0.3', $user),
        '10.44.0.1',
    ))
        ->toThrow(ResourceOperationException::class, 'could not be inspected')
        ->and(array_map(
            static fn (SshConnection $connection): string => $connection->user,
            $ssh->connections,
        ))
        ->each->toBe($user);
})->with([
    'invalid user' => 'invalid user',
    'missing user' => '',
    'unusable user' => 'nck121-noauth',
]);

it('publishes and verifies an ordered Gateway allow and other-peer deny boundary', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor(metricsPublicationConvergenceResults());

    metricsPublicationSshExecutor($ssh)->converge(
        metricsPublicationNode('metrics', '10.44.0.3'),
        '10.44.0.1',
    );

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        metricsPublicationInsertDenyArguments(),
        ['sudo', 'ufw', 'status', 'numbered'],
        ['sudo', 'ufw', 'status', 'numbered'],
        metricsPublicationInsertAllowArguments(),
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

it('leaves the deny rule in place when the Gateway allow cannot be applied', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: "Status: active\n"),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationDenyOnlyFirewallStatus()),
        metricsPublicationResult(stdout: metricsPublicationDenyOnlyFirewallStatus()),
        metricsPublicationResult(exitCode: 1),
    ]);

    expect(fn () => metricsPublicationSshExecutor($ssh)->converge(
        metricsPublicationNode('metrics', '10.44.0.3'),
        '10.44.0.1',
    ))->toThrow(ResourceOperationException::class, 'could not be applied');

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->not->toContain(['sudo', 'ufw', '--force', 'delete', '1']);
});

it('accepts an already complete boundary only when it precedes WireGuard trust', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
    ]);

    expect(metricsPublicationSshExecutor($ssh)->converge(
        metricsPublicationNode('metrics', '10.44.0.3'),
        '10.44.0.1',
    ))->toBeFalse()
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('refuses foreign Grafana firewall ownership before mutation', function (): void {
    $foreign = str_replace('10.44.0.1', '10.44.0.4', metricsPublicationFirewallStatus());
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: $foreign),
    ]);

    expect(fn () => metricsPublicationSshExecutor($ssh)->converge(
        metricsPublicationNode('metrics', '10.44.0.3'),
        '10.44.0.1',
    ))
        ->toThrow(ResourceOperationException::class, 'ownership cannot be proved')
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('refuses a complete boundary behind general WireGuard trust', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationMisorderedFirewallStatus()),
    ]);

    try {
        metricsPublicationSshExecutor($ssh)->converge(
            metricsPublicationNode('metrics', '10.44.0.3'),
            '10.44.0.1',
        );
        test()->fail('Expected the misordered boundary to fail closed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('metrics.publication_firewall_ordering_drift');
    }
});

it('removes only the proven Grafana firewall boundary and verifies absence', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationWireGuardOnlyStatus()),
    ]);

    metricsPublicationSshExecutor($ssh)->remove(
        metricsPublicationNode('metrics', '10.44.0.3'),
        '10.44.0.1',
    );

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        ['sudo', 'ufw', '--force', 'delete', '2'],
        ['sudo', 'ufw', '--force', 'delete', '1'],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

it('abandons both commented Grafana firewall rules without a Gateway address', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationWireGuardOnlyStatus()),
    ]);

    metricsPublicationSshExecutor($ssh)->abandon(metricsPublicationNode('metrics', '10.44.0.3'));

    expect(array_map(
        static fn (RemoteCommand $command): array => $command->arguments,
        $ssh->commands,
    ))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        ['sudo', 'ufw', '--force', 'delete', '2'],
        ['sudo', 'ufw', '--force', 'delete', '1'],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
});

it('does nothing when abandoning with no commented Grafana firewall rule present', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: "Status: active\n"),
    ]);

    metricsPublicationSshExecutor($ssh)->abandon(metricsPublicationNode('metrics', '10.44.0.3'));

    expect($ssh->commands)->toHaveCount(1);
});

it('fails closed when an abandoned Grafana firewall rule survives removal', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
    ]);

    try {
        metricsPublicationSshExecutor($ssh)->abandon(metricsPublicationNode('metrics', '10.44.0.3'));
        test()->fail('Expected abandon to fail closed when the rules survive removal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('metrics.publication_firewall_remove_verify_failed');
    }
});

it('leaves a neighbouring rule whose comment only starts with an Orbit marker', function (): void {
    $ssh = new MetricsPublicationCapturingSshExecutor([
        metricsPublicationResult(stdout: metricsPublicationNeighbourFirewallStatus()),
    ]);

    metricsPublicationSshExecutor($ssh)->abandon(metricsPublicationNode('metrics', '10.44.0.3'));

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

function metricsPublicationNode(string $name, string $address, string $user = 'orbit'): Node
{
    return new Node([
        'name' => $name,
        'wireguard_ip' => $address,
        'user' => $user,
    ]);
}

function metricsPublicationResult(int $exitCode = 0, string $stdout = ''): CommandResult
{
    return new CommandResult($exitCode, $stdout, '', 1, false);
}

/** @return list<CommandResult> */
function metricsPublicationConvergenceResults(): array
{
    return [
        metricsPublicationResult(stdout: "Status: active\n"),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationDenyOnlyFirewallStatus()),
        metricsPublicationResult(stdout: metricsPublicationDenyOnlyFirewallStatus()),
        metricsPublicationResult(),
        metricsPublicationResult(stdout: metricsPublicationFirewallStatus()),
    ];
}

/** @return list<string> */
function metricsPublicationInsertDenyArguments(): array
{
    return [
        'sudo', 'ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp',
        'from', 'any', 'to', '10.44.0.3', 'port', '3000',
        'comment', 'orbit:metrics-grafana-isolation',
    ];
}

/** @return list<string> */
function metricsPublicationInsertAllowArguments(): array
{
    return [
        'sudo', 'ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp',
        'from', '10.44.0.1', 'to', '10.44.0.3', 'port', '3000',
        'comment', 'orbit:metrics-grafana-upstream',
    ];
}

function metricsPublicationFirewallStatus(): string
{
    return <<<'STATUS'
        Status: active

        [ 1] 10.44.0.3 3000/tcp on orbit ALLOW IN 10.44.0.1 # orbit:metrics-grafana-upstream
        [ 2] 10.44.0.3 3000/tcp on orbit DENY IN Anywhere # orbit:metrics-grafana-isolation
        [ 3] 10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members
        STATUS;
}

function metricsPublicationDenyOnlyFirewallStatus(): string
{
    return <<<'STATUS'
        Status: active

        [ 1] 10.44.0.3 3000/tcp on orbit DENY IN Anywhere # orbit:metrics-grafana-isolation
        [ 2] 10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members
        STATUS;
}

function metricsPublicationWireGuardOnlyStatus(): string
{
    return <<<'STATUS'
        Status: active

        [ 1] 10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members
        STATUS;
}

function metricsPublicationMisorderedFirewallStatus(): string
{
    return <<<'STATUS'
        Status: active

        [ 1] 10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members
        [ 2] 10.44.0.3 3000/tcp on orbit ALLOW IN 10.44.0.1 # orbit:metrics-grafana-upstream
        [ 3] 10.44.0.3 3000/tcp on orbit DENY IN Anywhere # orbit:metrics-grafana-isolation
        STATUS;
}

final class MetricsPublicationCapturingSshExecutor implements SshExecutor
{
    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
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
