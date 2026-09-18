<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Processes\ProcessRuntime;
use App\Infrastructure\Metrics\PrometheusProcessMetricsQueries;
use App\Infrastructure\Processes\PrometheusProcessUsageIndex;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The Grafana credential the real manager would verify against a live Grafana, stubbed the same
 * way PrometheusProcessRuntimeStatusIndexTest stubs it for its own Prometheus-backed reads.
 */
function usage_index(): PrometheusProcessUsageIndex
{
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

    return app(PrometheusProcessUsageIndex::class);
}

/**
 * @param  list<array{name: string, value: float}>  $cpu
 * @param  list<array{name: string, value: float}>  $memory
 */
function prometheus_process_usage(array $cpu, array $memory): void
{
    $vector = static fn (array $samples): array => [
        'status' => 'success',
        'data' => [
            'resultType' => 'vector',
            'result' => array_map(
                static fn (array $sample): array => [
                    'metric' => ['name' => $sample['name']],
                    'value' => [1789700000, (string) $sample['value']],
                ],
                $samples,
            ),
        ],
    ];

    Http::fake([
        '*/api/datasources' => Http::response([['type' => 'prometheus', 'uid' => 'orbit-prometheus']]),
        '*/api/v1/query*' => static function (Request $request) use ($vector, $cpu, $memory) {
            $components = parse_url($request->url());
            parse_str($components['query'] ?? '', $params);
            $query = (string) ($params['query'] ?? '');

            return Http::response($vector(
                str_contains($query, 'rate(') ? $cpu : $memory,
            ));
        },
    ]);
}

function usage_index_process(int $id, string $name, ProcessRuntime $runtime = ProcessRuntime::Systemd): Process
{
    $process = new Process;
    $process->id = $id;
    $process->name = $name;
    $process->runtime = $runtime;
    $process->owner_type = AppInstance::class;
    $process->owner_id = 1;

    return $process;
}

describe(PrometheusProcessUsageIndex::class, function (): void {
    beforeEach(function (): void {
        activate_metrics_role();
    });

    it('reads CPU and memory for every Process from one query pair', function (): void {
        prometheus_process_usage(
            cpu: [
                ['name' => 'orbit-process-1-horizon.service', 'value' => 0.42],
                ['name' => 'orbit-process-2-valkey', 'value' => 0.05],
            ],
            memory: [
                ['name' => 'orbit-process-1-horizon.service', 'value' => 134217728],
                ['name' => 'orbit-process-2-valkey', 'value' => 20971520],
            ],
        );

        $usage = usage_index()->usage(new Collection([
            usage_index_process(1, 'horizon'),
            usage_index_process(2, 'valkey', ProcessRuntime::Docker),
        ]));

        expect($usage)->toBe([
            1 => ['cpu' => 0.42, 'memory_bytes' => 134217728],
            2 => ['cpu' => 0.05, 'memory_bytes' => 20971520],
        ]);
    });

    it('reports null, not zero, for a Process cAdvisor has no series for', function (): void {
        prometheus_process_usage(cpu: [], memory: []);

        $usage = usage_index()->usage(new Collection([
            usage_index_process(9, 'stopped-worker'),
        ]));

        expect($usage)->toBe([9 => ['cpu' => null, 'memory_bytes' => null]]);
    });

    it('never fails the list when cAdvisor is unreachable', function (): void {
        Http::fake(['*' => Http::response([], 503)]);

        $usage = usage_index()->usage(new Collection([
            usage_index_process(1, 'horizon'),
        ]));

        expect($usage)->toBe([1 => ['cpu' => null, 'memory_bytes' => null]]);
    });

    it('queries only the process cgroups, in one rate() window sized off the cadvisor scrape interval', function (): void {
        expect(PrometheusProcessMetricsQueries::cpu())
            ->toContain('container_cpu_usage_seconds_total')
            ->toContain('name=~"orbit-process-.*"')
            ->toContain('['.PrometheusProcessMetricsQueries::CpuRateWindow.']')
            ->and(PrometheusProcessMetricsQueries::memory())
            ->toContain('container_memory_usage_bytes')
            ->toContain('name=~"orbit-process-.*"');
    });
});
