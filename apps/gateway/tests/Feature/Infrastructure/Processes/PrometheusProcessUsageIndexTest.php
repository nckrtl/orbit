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
 * A cAdvisor series as cAdvisor actually labels a container: `name` is the container name, and
 * `id` is the opaque docker scope carrying nothing identifying.
 *
 * @return array{labels: array<string, string>, value: float}
 */
function cadvisor_container(string $container, float $value): array
{
    return [
        'labels' => ['name' => $container, 'id' => '/system.slice/docker-'.str_repeat('a', 64).'.scope'],
        'value' => $value,
    ];
}

/**
 * A cAdvisor series as cAdvisor actually labels a systemd unit: a raw cgroup with no `name` label
 * at all, identified only by the unit name its `id` path ends in. Measured against the live fleet.
 *
 * @return array{labels: array<string, string>, value: float}
 */
function cadvisor_unit(string $unit, float $value): array
{
    return ['labels' => ['id' => '/system.slice/'.$unit], 'value' => $value];
}

/**
 * @param  list<array{labels: array<string, string>, value: float}>  $cpu
 * @param  list<array{labels: array<string, string>, value: float}>  $memory
 */
function prometheus_process_usage(array $cpu, array $memory): void
{
    $vector = static fn (array $samples): array => [
        'status' => 'success',
        'data' => [
            'resultType' => 'vector',
            'result' => array_map(
                static fn (array $sample): array => [
                    'metric' => $sample['labels'],
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
        // A systemd unit answers with no `name` label, a container with one: reading only `name`
        // reports the container's usage and leaves the unit at null, which is the whole fleet's
        // systemd Processes showing nothing.
        prometheus_process_usage(
            cpu: [
                cadvisor_unit('orbit-process-1-horizon.service', 0.42),
                cadvisor_container('orbit-process-2-valkey', 0.05),
            ],
            memory: [
                cadvisor_unit('orbit-process-1-horizon.service', 134217728),
                cadvisor_container('orbit-process-2-valkey', 20971520),
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

    it('ignores a cgroup that is neither a container nor a Process unit', function (): void {
        // The `id` arm matches by path, so a series for some other cgroup must not be read as the
        // usage of whichever Process happens to be asked for.
        prometheus_process_usage(
            cpu: [cadvisor_unit('ssh.service', 0.9)],
            memory: [cadvisor_unit('ssh.service', 1048576)],
        );

        $usage = usage_index()->usage(new Collection([usage_index_process(1, 'horizon')]));

        expect($usage)->toBe([1 => ['cpu' => null, 'memory_bytes' => null]]);
    });

    it('asks for both cgroup shapes, in one rate() window sized off the cadvisor scrape interval', function (): void {
        // cAdvisor names a container and leaves a systemd unit identified only by its cgroup path,
        // so a query matching one label shape silently covers only half the fleet.
        expect(PrometheusProcessMetricsQueries::cpu())
            ->toContain('container_cpu_usage_seconds_total')
            ->toContain('name=~"orbit-process-.*"')
            ->toContain('id=~".*/orbit-process-.*\\\\.service"')
            ->toContain('['.PrometheusProcessMetricsQueries::CpuRateWindow.']')
            ->and(PrometheusProcessMetricsQueries::memory())
            ->toContain('container_memory_usage_bytes')
            ->toContain('name=~"orbit-process-.*"')
            ->toContain('id=~".*/orbit-process-.*\\\\.service"');
    });

    it('escapes every backslash, which Prometheus refuses a query without', function (): void {
        // A PromQL string is Go-quoted before it compiles as a regex, so a lone `\` is not an
        // escape Prometheus knows and it rejects the whole query with a parse error rather than
        // returning no series. Writing the selector by hand makes that a one-character mistake
        // that no assertion on the query's text would catch, because the text still looks right.
        foreach ([PrometheusProcessMetricsQueries::cpu(), PrometheusProcessMetricsQueries::memory()] as $query) {
            expect(preg_match('/(?<!\\\\)\\\\(?!\\\\)/', $query))->toBe(0, "lone backslash in: {$query}");
        }
    });
});
