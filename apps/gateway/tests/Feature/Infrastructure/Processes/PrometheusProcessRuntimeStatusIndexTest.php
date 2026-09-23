<?php

declare(strict_types=1);

use App\Actions\Processes\ListProcessesAction;
use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\PrometheusProcessRuntimeStatusIndex;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/**
 * @param  list<array{name: string, state: string}>  $units
 * @param  list<string>  $containers  Running containers cAdvisor reports, by name.
 */
function prometheus_unit_states(array $units, array $containers = []): void
{
    Http::fake([
        '*/api/datasources' => Http::response([['type' => 'prometheus', 'uid' => 'orbit-prometheus']]),
        '*/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'vector',
                'result' => [
                    ...array_map(
                        static fn (array $unit): array => [
                            'metric' => ['name' => $unit['name'], 'state' => $unit['state']],
                            'value' => [1789700000, '1'],
                        ],
                        $units,
                    ),
                    ...array_map(
                        static fn (string $container): array => [
                            'metric' => ['__name__' => 'container_last_seen', 'name' => $container, 'image' => 'valkey:8'],
                            'value' => [1789700000, '1789700000'],
                        ],
                        $containers,
                    ),
                ],
            ],
        ]),
    ]);
}

function status_index_process(int $id, string $name, ProcessRuntime $runtime = ProcessRuntime::Systemd): Process
{
    $process = new Process;
    $process->id = $id;
    $process->name = $name;
    $process->runtime = $runtime;
    $process->owner_type = AppInstance::class;
    $process->owner_id = 1;
    $process->status = LifecycleStatus::Active;

    return $process;
}

/**
 * The index with both collaborators stubbed: Grafana's stored credential, which the real manager
 * would verify against a live Grafana, and the Node-asking fallback, which answers
 * 'nodes-were-asked' so a test can tell the two paths apart.
 */
function status_index_with_fake_node_reads(): PrometheusProcessRuntimeStatusIndex
{
    $runtime = new ProcessesApiFakeRuntimeManager;
    $runtime->statusOverride = 'nodes-were-asked';
    app()->instance(ProcessRuntimeManager::class, $runtime);

    app()->instance(MetricsCredentialManager::class, new class implements MetricsCredentialManager
    {
        public function passwordForConvergence(Node $node): string
        {
            return 'password';
        }

        public function verifyActive(Node $node): void {}

        public function purge(Node $node): void {}

        public function credentials(): MetricsCredentialsData
        {
            return new MetricsCredentialsData('https://metrics.orbit', 'admin', 'password');
        }

        public function storedCredentials(): MetricsCredentialsData
        {

            return $this->credentials();

        }

        public function reset(): MetricsCredentialsData
        {
            return $this->credentials();
        }
    });

    return app(PrometheusProcessRuntimeStatusIndex::class);
}

describe(PrometheusProcessRuntimeStatusIndex::class, function (): void {
    beforeEach(function (): void {
        activate_metrics_role();
    });

    it('reads every systemd unit state from one query rather than one round trip per Process', function (): void {
        prometheus_unit_states([
            ['name' => 'orbit-process-1-horizon.service', 'state' => 'active'],
            ['name' => 'orbit-process-2-vite.service', 'state' => 'failed'],
        ]);

        $statuses = status_index_with_fake_node_reads()->statuses(new Collection([
            status_index_process(1, 'horizon'),
            status_index_process(2, 'vite'),
        ]));

        // Neither is the fallback's answer, so no Node was asked for either one.
        expect($statuses)->toBe([1 => 'active', 2 => 'failed']);
    });

    it('reports a systemd unit Prometheus has no series for as inactive', function (): void {
        prometheus_unit_states([['name' => 'orbit-process-1-horizon.service', 'state' => 'active']]);

        $statuses = status_index_with_fake_node_reads()->statuses(new Collection([
            status_index_process(9, 'stopped-worker'),
        ]));

        // systemd keeps no unit entry for a stopped Process, so absence is the answer, not a
        // reason to ask its Node.
        expect($statuses)->toBe([9 => 'inactive']);
    });

    it('asks the Node when Prometheus cannot answer at all, rather than calling every unit stopped', function (): void {
        Http::fake(['*' => Http::response([], 503)]);

        $statuses = status_index_with_fake_node_reads()->statuses(new Collection([
            status_index_process(1, 'horizon'),
        ]));

        expect($statuses)->toBe([1 => 'nodes-were-asked']);
    });

    it('reads a Docker Process from the containers cAdvisor reports', function (): void {
        prometheus_unit_states([], ['orbit-process-3-valkey']);

        $statuses = status_index_with_fake_node_reads()->statuses(new Collection([
            status_index_process(3, 'valkey', ProcessRuntime::Docker),
            status_index_process(4, 'plausible', ProcessRuntime::Docker),
        ]));

        // cAdvisor reports running containers only, so a container without a series has exited.
        expect($statuses)->toBe([3 => 'running', 4 => 'exited']);
    });

    it('answers every list within the cache window from one query', function (): void {
        prometheus_unit_states([['name' => 'orbit-process-1-horizon.service', 'state' => 'active']], ['orbit-process-3-valkey']);
        $index = status_index_with_fake_node_reads();
        $processes = new Collection([
            status_index_process(1, 'horizon'),
            status_index_process(3, 'valkey', ProcessRuntime::Docker),
        ]);

        $index->statuses($processes);
        $second = $index->statuses($processes);

        expect($second)->toBe([1 => 'active', 3 => 'running']);
        Http::assertSentCount(2);
    });

    it('does not cache a read Prometheus could not answer', function (): void {
        Http::fake(['*' => Http::response([], 503)]);
        $index = status_index_with_fake_node_reads();

        expect($index->statuses(new Collection([status_index_process(1, 'horizon')])))->toBe([1 => 'nodes-were-asked'])
            ->and(Cache::get('processes.runtime-states'))->toBeNull();
    });
});

describe('listing the whole fleet', function (): void {
    it('lists only Processes whose owner a target could name', function (): void {
        activate_metrics_role();
        prometheus_unit_states([]);

        $node = Node::query()->create([
            'name' => 'fleet-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.70',
            'wireguard_ip' => '10.44.0.70',
            'user' => 'orbit',
        ]);

        $owned = Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $node->id,
            'name' => 'valkey',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/srv',
            'runtime_config' => [],
            'restart_policy' => 'always',
            'keep_alive' => false,
            'desired_state' => 'running',
            'status' => LifecycleStatus::Active,
        ]);

        // A real fleet carries Processes owned by a legacy model that no target selects; listing
        // every target one by one never returned them, so listing the fleet must not either.
        Process::query()->create([
            'owner_type' => 'App\\Models\\Instance',
            'owner_id' => 999,
            'name' => 'legacy-worker',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/srv',
            'runtime_config' => [],
            'restart_policy' => 'always',
            'keep_alive' => false,
            'desired_state' => 'running',
            'status' => LifecycleStatus::Active,
        ]);

        $listed = app(ListProcessesAction::class)->executeAll();

        expect($listed->pluck('name')->all())->toBe([$owned->name]);
    });
});
