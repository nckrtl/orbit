<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Queue\AppInstanceQueueReader;
use App\Domain\Processes\ProcessRuntime;
use App\Models\AppInstance;
use App\Models\Process;

final readonly class ShowAppInstanceQueueAction
{
    private const array States = ['pending', 'completed', 'failed'];

    public function __construct(private AppInstanceQueueReader $queues) {}

    /**
     * @param  'pending'|'completed'|'failed'  $state
     * @return array<string, mixed>
     */
    public function execute(AppInstance $instance, string $state, int $limit): array
    {
        $horizon = $this->horizonProcess($instance);
        $report = $horizon === null ? [] : $this->queues->read($horizon, $state, $limit);

        if ($horizon === null || ($report['installed'] ?? false) !== true) {
            return ['available' => false, 'state' => $state];
        }

        $domain = ($instance->authoritativeRoute() ?? $instance->routes->first())?->domain;
        $dashboard = is_string($domain) && $domain !== ''
            ? "https://{$domain}/".trim($this->text($report['path'] ?? 'horizon', 100), '/')
            : null;
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];

        // The report was printed inside the application. Every value is cast and bounded again
        // here, and only these fields leave the Gateway, so a job payload can never pass through.
        return [
            'available' => true,
            'process_id' => $horizon->id,
            'status' => $this->text($report['status'] ?? '', 20),
            'jobs_per_minute' => $this->count($report['jobs_per_minute'] ?? 0),
            'recent_jobs' => $this->count($report['recent_jobs'] ?? 0),
            'recently_failed_jobs' => $this->count($report['recently_failed_jobs'] ?? 0),
            'processes' => $this->count($report['processes'] ?? 0),
            'totals' => array_combine(
                self::States,
                array_map(fn (string $name): int => $this->count($totals[$name] ?? 0), self::States),
            ),
            'queues' => $this->rows($report['queues'] ?? [], 50, fn (array $queue): array => [
                'name' => $this->text($queue['name'] ?? '', 100),
                'length' => $this->count($queue['length'] ?? 0),
                'wait_seconds' => $this->count($queue['wait_seconds'] ?? 0),
                'processes' => $this->count($queue['processes'] ?? 0),
            ]),
            'dashboard_url' => $dashboard,
            'state' => $state,
            'jobs' => $this->rows($report['jobs'] ?? [], $limit, function (array $job) use ($dashboard, $state): array {
                $id = $this->text($job['id'] ?? '', 100);

                return [
                    'id' => $id,
                    'name' => $this->text($job['name'] ?? '', 200),
                    'queue' => $this->text($job['queue'] ?? '', 100),
                    'status' => $this->text($job['status'] ?? '', 20),
                    'pushed_at' => $this->time($job['pushed_at'] ?? null),
                    'completed_at' => $this->time($job['completed_at'] ?? null),
                    'failed_at' => $this->time($job['failed_at'] ?? null),
                    'exception' => isset($job['exception']) ? $this->text($job['exception'], 300) : null,
                    'url' => $dashboard === null || preg_match('/\A[A-Za-z0-9-]+\z/', $id) !== 1
                        ? null
                        : $dashboard.($state === 'failed' ? '/failed/' : "/jobs/{$state}/").$id,
                ];
            }),
        ];
    }

    /** The systemd Process of the instance whose command is `artisan horizon`. */
    private function horizonProcess(AppInstance $instance): ?Process
    {
        return $instance->processes
            ->first(static function (Process $process): bool {
                $command = $process->runtime_config['command'] ?? null;

                return $process->runtime === ProcessRuntime::Systemd
                    && is_array($command)
                    && array_slice($command, 1, 2) === ['artisan', 'horizon'];
            });
    }

    /**
     * @param  callable(array<array-key, mixed>): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows, int $limit, callable $map): array
    {
        return array_values(array_map(
            $map,
            array_slice(array_filter(is_array($rows) ? $rows : [], is_array(...)), 0, $limit),
        ));
    }

    private function text(mixed $value, int $length): string
    {
        return is_scalar($value)
            ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value), 0, $length)
            : '';
    }

    private function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) === 1
            ? $value
            : null;
    }
}
