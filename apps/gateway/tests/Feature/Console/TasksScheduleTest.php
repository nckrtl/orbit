<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentMetricCollector;
use App\Domain\Tasks\ArchiveFinishedTaskThreads;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskSchedule;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\TaskGroup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

function task_schedule(bool $enabled): Schedule
{
    $extension = app(TaskExtensionState::class);
    $enabled ? $extension->enable() : $extension->disable();

    $schedule = new Schedule;
    app(TaskSchedule::class)->register($schedule);

    return $schedule;
}

it('registers the task tick on the schedule when tasks are enabled', function (): void {
    $schedule = task_schedule(enabled: true);

    $commands = array_map(static fn ($event): string => $event->command, $schedule->events());
    expect($schedule->events())->toHaveCount(2)
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:tick')))->toBeTrue()
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:collect-t3-metrics')))->toBeTrue()
        ->and($schedule->events()[0]->filtersPass(app()))->toBeTrue()
        ->and($schedule->events()[1]->filtersPass(app()))->toBeTrue();
});

it('skips the task tick schedule when tasks are disabled', function (): void {
    $schedule = task_schedule(enabled: false);

    expect($schedule->events())
        ->toHaveCount(2)
        ->and($schedule->events()[0]->filtersPass(app()))
        ->toBeFalse()
        ->and($schedule->events()[1]->filtersPass(app()))
        ->toBeFalse();
});

it('safely no-ops when the task tick command runs while tasks are disabled', function (): void {
    Artisan::call('tasks:tick');

    expect(Artisan::output())->toContain('Tasks extension is disabled.');
});

it('reads only threads that can still change', function (): void {
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create(['name' => 'collector-scope', 'slug' => 'collector-scope', 'repository_url' => 'https://example.test/repo.git', 'default_branch' => 'main']);

    $thread = static function (string $groupStatus, string $state, ?string $finalAt = null) use ($app): AgentThread {
        $group = TaskGroup::query()->create([
            'app_id' => $app->id,
            'title' => 'Metrics scope',
            'brief' => 'Test collector scope',
            'status' => $groupStatus,
        ]);

        return AgentThread::query()->create([
            'task_group_id' => $group->id,
            'driver' => 't3',
            'runtime_key' => 'node:1',
            'external_id' => 'thread-'.$groupStatus.'-'.$state.'-'.uniqid(),
            'role' => 'implementer',
            'state' => $state,
            't3_metrics_final_at' => $finalAt,
            't3_metrics_observed_activity_version' => 0,
        ]);
    };

    $final = $thread('completed', 'done', now()->subMinute()->toDateTimeString());
    $cancelled = $thread('cancelled', 'done', now()->subMinute()->toDateTimeString());
    $open = $thread('running', 'working');
    $justFinished = $thread('running', 'done');

    $collectedIds = [];
    $driver = Mockery::mock(AgentDriver::class, AgentMetricCollector::class);
    $driver->shouldReceive('key')->andReturn('t3');
    $driver->shouldReceive('collectMetrics')->times(3)->andReturnUsing(static function (AgentThread $collected) use (&$collectedIds): bool {
        $collectedIds[] = $collected->id;

        return true;
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    Artisan::call('tasks:collect-t3-metrics');
    Artisan::call('tasks:collect-t3-metrics');

    expect($collectedIds)->toBe([$open->id, $justFinished->id, $open->id])
        ->and($final->fresh()->t3_metrics_final_at)->not->toBeNull()
        ->and($cancelled->fresh()->t3_metrics_final_at)->not->toBeNull()
        ->and($open->fresh()->t3_metrics_final_at)->toBeNull()
        ->and($justFinished->fresh()->t3_metrics_final_at)->not->toBeNull();
});

it('backs off incomplete heartbeat timeouts until a successful final collection', function (): void {
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create(['name' => 'collector-retry', 'slug' => 'collector-retry', 'repository_url' => 'https://example.test/repo.git', 'default_branch' => 'main']);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Settled thread',
        'brief' => 'Await successful final read',
        'status' => 'completed',
    ]);
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id,
        'driver' => 't3',
        'runtime_key' => 'node:1',
        'external_id' => 'settled-heartbeat-thread',
        'role' => 'implementer',
        'state' => 'done',
    ]);
    $driver = Mockery::mock(AgentDriver::class, AgentMetricCollector::class);
    $driver->shouldReceive('key')->andReturn('t3');
    $driver->shouldReceive('collectMetrics')->twice()->andReturn(false, true);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    Artisan::call('tasks:collect-t3-metrics');
    expect($thread->fresh()->t3_metrics_final_at)->toBeNull()
        ->and($thread->fresh()->t3_metrics_collected_at)->toBeNull()
        ->and($thread->fresh()->t3_metrics_retry_at)->not->toBeNull();

    Carbon::setTestNow(now()->addMinute());
    Artisan::call('tasks:collect-t3-metrics');

    expect($thread->fresh()->t3_metrics_final_at)->not->toBeNull()
        ->and($thread->fresh()->t3_metrics_collected_at)->not->toBeNull()
        ->and($thread->fresh()->t3_metrics_retry_at)->toBeNull();
});

it('waits for the final T3 metrics read before archiving a terminal thread', function (): void {
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create(['name' => 'archive-after-metrics', 'slug' => 'archive-after-metrics', 'repository_url' => 'https://example.test/repo.git']);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Terminal before collector',
        'brief' => 'Read final metrics before archiving',
        'status' => 'completed',
    ]);
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id,
        'driver' => 't3',
        'runtime_key' => 'node:1',
        'external_id' => 'terminal-before-collector',
        'role' => 'implementer',
        'state' => 'done',
    ]);
    $driver = Mockery::mock(AgentDriver::class, AgentMetricCollector::class);
    $driver->shouldReceive('key')->andReturn('t3');
    $driver->shouldReceive('collectMetrics')->once()->andReturnUsing(static function (AgentThread $collected) use ($thread): bool {
        expect($collected->id)->toBe($thread->id)
            ->and($collected->archived_at)->toBeNull();

        return true;
    });
    $driver->shouldReceive('archive')->once()->withArgs(static fn (AgentThread $archived): bool => $archived->id === $thread->id
        && $archived->t3_metrics_final_at !== null);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    app(ArchiveFinishedTaskThreads::class)->run();

    expect($thread->fresh()->archived_at)->toBeNull();
    Artisan::call('tasks:collect-t3-metrics');

    expect($thread->fresh()->t3_metrics_final_at)->not->toBeNull();
    app(ArchiveFinishedTaskThreads::class)->run();

    expect($thread->fresh()->archived_at)->not->toBeNull();
});

it('lets healthy threads through a persistently failing batch and throttles reports per thread and kind', function (): void {
    app(TaskExtensionState::class)->enable();
    Log::spy();
    $app = OrbitApp::query()->create(['name' => 'collector-fairness', 'slug' => 'collector-fairness', 'repository_url' => 'https://example.test/repo.git', 'default_branch' => 'main']);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Collector backlog',
        'brief' => 'Exercise retry fairness',
        'status' => 'running',
    ]);
    $threads = [];
    for ($index = 1; $index <= 21; $index++) {
        $threads[] = AgentThread::query()->create([
            'task_group_id' => $group->id,
            'driver' => 't3',
            'runtime_key' => 'node:1',
            'external_id' => 'backlog-thread-'.$index,
            'role' => 'implementer',
            'state' => 'working',
        ]);
    }
    $failedIds = array_fill_keys(array_map(static fn (AgentThread $thread): int => $thread->id, array_slice($threads, 0, 20)), true);
    $attemptedIds = [];
    $driver = Mockery::mock(AgentDriver::class, AgentMetricCollector::class);
    $driver->shouldReceive('key')->andReturn('t3');
    $driver->shouldReceive('collectMetrics')->times(41)->andReturnUsing(static function (AgentThread $thread) use (&$attemptedIds, $failedIds): bool {
        $attemptedIds[] = $thread->id;
        if (isset($failedIds[$thread->id])) {
            throw new RuntimeException('Node stream unavailable.');
        }

        return true;
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    Artisan::call('tasks:collect-t3-metrics');
    Artisan::call('tasks:collect-t3-metrics');
    expect($attemptedIds)->toHaveCount(21)
        ->and($attemptedIds)->toContain($threads[20]->id);

    Carbon::setTestNow(now()->addMinute());
    Artisan::call('tasks:collect-t3-metrics');

    expect(array_count_values($attemptedIds))->toHaveKey($threads[20]->id)
        ->and($threads[20]->fresh()->t3_metrics_collected_at)->not->toBeNull();
    Log::shouldHaveReceived('error')->times(20);
});

it('gives a due failed thread priority over a full batch of recently collected healthy threads', function (): void {
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create(['name' => 'collector-due-retry', 'slug' => 'collector-due-retry', 'repository_url' => 'https://example.test/repo.git', 'default_branch' => 'main']);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Due retry fairness',
        'brief' => 'A due retry must progress',
        'status' => 'running',
    ]);
    $healthy = [];
    for ($index = 1; $index <= 20; $index++) {
        $healthy[] = AgentThread::query()->create([
            'task_group_id' => $group->id,
            'driver' => 't3',
            'runtime_key' => 'node:1',
            'external_id' => 'recent-healthy-'.$index,
            'role' => 'implementer',
            'state' => 'working',
            't3_metrics_collected_at' => now(),
        ]);
    }
    $due = AgentThread::query()->create([
        'task_group_id' => $group->id,
        'driver' => 't3',
        'runtime_key' => 'node:1',
        'external_id' => 'previously-failed-now-due',
        'role' => 'implementer',
        'state' => 'working',
        't3_metrics_retry_at' => now()->subMinute(),
        't3_metrics_attempts' => 2,
        't3_metrics_collected_at' => now()->subMinutes(5),
    ]);
    $collectedIds = [];
    $driver = Mockery::mock(AgentDriver::class, AgentMetricCollector::class);
    $driver->shouldReceive('key')->andReturn('t3');
    $driver->shouldReceive('collectMetrics')->times(20)->andReturnUsing(static function (AgentThread $thread) use (&$collectedIds): bool {
        $collectedIds[] = $thread->id;

        return true;
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    Artisan::call('tasks:collect-t3-metrics');

    expect($collectedIds)->toHaveCount(20)
        ->and($collectedIds)->toContain($due->id)
        ->and($due->fresh()->t3_metrics_retry_at)->toBeNull()
        ->and($healthy)->toHaveCount(20);
});
