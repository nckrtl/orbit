<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\Metrics\PrometheusFleetMetricsSshReader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fleetMetricsNode(string $name = 'metrics', string $wireguardIp = '10.44.0.2'): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, -1),
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);

    return $node;
}

/** @return non-empty-string */
function fleetMetricsTranscript(): string
{
    $scalars = [
        'status' => 'success',
        'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'app-dev'], 'value' => [1_700_000_000, '4294967296']],
            ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'app-dev'], 'value' => [1_700_000_000, '1073741824']],
        ]],
    ];
    $cores = ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]];
    $pressure = ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]];
    $disks = ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]];

    return
        "===orbit:scalars===\n".json_encode($scalars, JSON_THROW_ON_ERROR)."\n"
        ."===orbit:cores===\n".json_encode($cores, JSON_THROW_ON_ERROR)."\n"
        ."===orbit:pressure===\n".json_encode($pressure, JSON_THROW_ON_ERROR)."\n"
        ."===orbit:disks===\n".json_encode($disks, JSON_THROW_ON_ERROR)."\n";
}

function fleetMetricsReader(SshExecutor $ssh): PrometheusFleetMetricsSshReader
{
    return new PrometheusFleetMetricsSshReader($ssh, new PrometheusFleetSshKeyProviderFake, new PrometheusFleetKnownHostsStoreFake);
}

it('reads and maps the four-section transcript from the Metrics node', function (): void {
    $metricsNode = fleetMetricsNode();
    $ssh = new PrometheusFleetCapturingSshExecutor([
        new CommandResult(0, fleetMetricsTranscript(), '', 5, false),
    ]);

    $result = fleetMetricsReader($ssh)->read();

    expect($result->metricsNode->is($metricsNode))->toBeTrue()
        ->and($result->snapshots)->toHaveKey('app-dev')
        ->and($result->snapshots['app-dev']['memory'])->toBe(['used' => 3_221_225_472, 'total' => 4_294_967_296])
        ->and($ssh->connections)->toHaveCount(1)
        ->and($ssh->connections[0]->host)->toBe('10.44.0.2');

    $script = $ssh->commands[0]->input;
    expect($script)
        ->toContain('===orbit:scalars===')
        ->toContain('===orbit:cores===')
        ->toContain('===orbit:pressure===')
        ->toContain('===orbit:disks===')
        ->toContain('node_memory_MemTotal_bytes')
        ->toContain('rate(node_cpu_seconds_total');
});

it('fails when no Node carries the Metrics role', function (): void {
    $ssh = new PrometheusFleetCapturingSshExecutor([]);

    fleetMetricsReader($ssh)->read();
})->throws(ResourceOperationException::class);

it('fails closed when more than one Node carries the Metrics role', function (): void {
    fleetMetricsNode('metrics-one', '10.44.0.2');
    fleetMetricsNode('metrics-two', '10.44.0.3');
    $ssh = new PrometheusFleetCapturingSshExecutor([]);

    fleetMetricsReader($ssh)->read();
})->throws(RoleAssignmentException::class);

it('fails when the Metrics node is not active', function (): void {
    $metricsNode = fleetMetricsNode();
    $metricsNode->update(['status' => LifecycleStatus::Provisioning]);
    $ssh = new PrometheusFleetCapturingSshExecutor([]);

    fleetMetricsReader($ssh)->read();
})->throws(ResourceOperationException::class);

it('answers node.metrics_unreachable when the SSH command fails', function (): void {
    fleetMetricsNode();
    $ssh = new PrometheusFleetCapturingSshExecutor([
        new CommandResult(255, '', 'connection refused', 5, false),
    ]);

    try {
        fleetMetricsReader($ssh)->read();
        $this->fail('Expected a ResourceOperationException.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.metrics_unreachable');
    }
});

it('answers node.metrics_unreachable when the transcript is malformed', function (): void {
    fleetMetricsNode();
    $ssh = new PrometheusFleetCapturingSshExecutor([
        new CommandResult(0, "not a transcript\n", '', 5, false),
    ]);

    try {
        fleetMetricsReader($ssh)->read();
        $this->fail('Expected a ResourceOperationException.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.metrics_unreachable');
    }
});

final readonly class PrometheusFleetSshKeyProviderFake implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/fleet-metrics-key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 fleet-metrics-key';
    }
}

final readonly class PrometheusFleetKnownHostsStoreFake implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/fleet-metrics-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class PrometheusFleetCapturingSshExecutor implements SshExecutor
{
    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @param list<CommandResult> $results */
    public function __construct(private array $results) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        return array_shift($this->results) ?? new CommandResult(255, '', 'no result queued', 0, false);
    }
}
