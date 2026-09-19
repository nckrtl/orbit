<?php

// Read-only report of a Laravel Horizon queue, run by the Gateway in an App instance's checkout.
// Arguments: the job state (pending, completed, failed) and the number of jobs to list.
// It prints one JSON document after a marker line, and never a job payload.

declare(strict_types=1);

$state = $argv[1] ?? 'pending';
$limit = max(1, min(50, (int) ($argv[2] ?? 50)));

if (! in_array($state, ['pending', 'completed', 'failed'], true)) {
    exit(2);
}

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$report = ['installed' => interface_exists(Laravel\Horizon\Contracts\JobRepository::class)];

if ($report['installed']) {
    $jobs = $app->make(Laravel\Horizon\Contracts\JobRepository::class);
    $masters = $app->make(Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
    $paused = count(array_filter($masters, static fn ($master): bool => $master->status === 'paused'));
    $processes = 0;

    foreach ($app->make(Laravel\Horizon\Contracts\SupervisorRepository::class)->all() as $supervisor) {
        $processes += array_sum((array) $supervisor->processes);
    }

    $listed = match ($state) {
        'pending' => $jobs->getPending(),
        'completed' => $jobs->getCompleted(),
        'failed' => $jobs->getFailed(),
    };
    $time = static function ($value): ?string {
        return is_numeric($value) ? gmdate('Y-m-d\TH:i:s\Z', (int) $value) : null;
    };

    $report += [
        'path' => (string) config('horizon.path', 'horizon'),
        'status' => $masters === [] ? 'inactive' : ($paused === count($masters) ? 'paused' : 'running'),
        'jobs_per_minute' => (int) $app->make(Laravel\Horizon\Contracts\MetricsRepository::class)->jobsProcessedPerMinute(),
        'recent_jobs' => (int) $jobs->countRecent(),
        'recently_failed_jobs' => (int) $jobs->countRecentlyFailed(),
        'processes' => (int) $processes,
        'totals' => [
            'pending' => (int) $jobs->countPending(),
            'completed' => (int) $jobs->countCompleted(),
            'failed' => (int) $jobs->countFailed(),
        ],
        'queues' => array_map(static fn (array $queue): array => [
            'name' => (string) $queue['name'],
            'length' => (int) $queue['length'],
            'wait_seconds' => (int) $queue['wait'],
            'processes' => (int) $queue['processes'],
        ], $app->make(Laravel\Horizon\Contracts\WorkloadRepository::class)->get()),
        'jobs' => $listed->take($limit)->map(static function ($job) use ($time): array {
            $payload = json_decode((string) ($job->payload ?? ''), true);

            return [
                'id' => (string) $job->id,
                'name' => (string) ($job->name ?? ''),
                'queue' => (string) ($job->queue ?? ''),
                'status' => (string) ($job->status ?? ''),
                'pushed_at' => $time(is_array($payload) ? ($payload['pushedAt'] ?? null) : null),
                'completed_at' => $time($job->completed_at ?? null),
                'failed_at' => $time($job->failed_at ?? null),
                // The first line names the exception; the rest is a stack trace with arguments.
                'exception' => ($job->exception ?? '') === '' ? null : mb_substr(strtok((string) $job->exception, "\n") ?: '', 0, 300),
            ];
        })->values()->all(),
    ];
}

echo "\n--orbit-queue--\n".json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
