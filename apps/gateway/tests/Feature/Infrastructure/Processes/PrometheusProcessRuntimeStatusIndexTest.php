<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/** @param  list<array{name: string, state: string}>  $units */
function prometheus_unit_states(array $units): void
{
    Http::fake([
        '*/api/datasources' => Http::response([['type' => 'prometheus', 'uid' => 'orbit-prometheus']]),
        '*/api/v1/query*' => Http::response([
            'status' => 'success',
            'data' => [
                'resultType' => 'vector',
                'result' => array_map(
                    static fn (array $unit): array => [
                        'metric' => ['__name__' => 'node_systemd_unit_state', 'name' => $unit['name'], 'state' => $unit['state']],
                        'value' => [1789700000, '1'],
                    ],
                    $units,
                ),
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

    it('asks the Node for a Docker Process, which the systemd metric never covers', function (): void {
        prometheus_unit_states([]);

        $statuses = status_index_with_fake_node_reads()->statuses(new Collection([
            status_index_process(3, 'valkey', ProcessRuntime::Docker),
        ]));

        expect($statuses)->toBe([3 => 'nodes-were-asked']);
    });
});
