<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentMetricCollector;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3Driver;
use App\Infrastructure\Tasks\T3\T3NodeEligibility;
use App\Infrastructure\Tasks\T3\T3Projection;
use App\Infrastructure\Tasks\T3\T3SendLeaseManager;
use App\Infrastructure\Tasks\T3\T3Stream;
use App\Infrastructure\Tasks\T3\T3ThreadCreator;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\AgentThread;
use App\Models\AgentThreadSendLease;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

/** @return array{AgentThread, T3Driver, MockInterface, MockInterface} */
function metrics_collection_driver(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'T3 metrics lifecycle',
        'slug' => 't3-metrics-lifecycle',
        'repository_url' => 'https://example.test/t3-metrics.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 't3-metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.200',
        'wireguard_ip' => '10.44.0.200',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Metrics lifecycle',
        'brief' => 'Exercise T3 metric stream behavior',
        'status' => 'running',
    ]);
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id,
        'node_id' => $node->id,
        'driver' => 't3',
        'runtime_key' => 'node:'.$node->id,
        'external_id' => 'metrics-thread-1',
        'role' => 'implementer',
        'model' => 'gpt-5.6-luna',
        'state' => AgentThreadState::Done,
        't3_metrics_final_at' => now()->subMinute(),
        't3_metrics_observed_activity_version' => 0,
    ]);

    $dispatcher = Mockery::mock(T3Dispatcher::class);
    $stream = Mockery::mock(T3Stream::class);
    $driver = new T3Driver(
        $dispatcher,
        Mockery::mock(T3ThreadReader::class),
        new T3ThreadCreator($dispatcher),
        $stream,
        new T3Projection,
        new T3NodeEligibility,
        new T3SendLeaseManager,
    );

    return [$thread, $driver, $stream, $dispatcher];
}

it('does not finalize heartbeat-only timeouts and retries until a snapshot arrives', function (): void {
    [$thread, $driver, $stream] = metrics_collection_driver();
    $stream->shouldReceive('events')->twice()->andReturn(
        [['kind' => 'heartbeat']],
        [[
            'kind' => 'snapshot',
            'snapshot' => [
                'thread' => ['id' => $thread->external_id, 'session' => ['status' => 'done']],
                'snapshotSequence' => 1,
            ],
        ]],
    );

    expect($driver->collectMetrics($thread))->toBeFalse()
        ->and($driver->collectMetrics($thread))->toBeTrue();
});

it('reopens finalized metrics through the real T3 send path', function (): void {
    [$thread, $driver, , $dispatcher] = metrics_collection_driver();
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->withArgs(static fn (Node $node, array $command): bool => $command['type'] === 'thread.turn.start'
            && $command['threadId'] === 'metrics-thread-1')
        ->andReturn(['sequence' => 1, 'thread_id' => 'metrics-thread-1']);

    $driver->send($thread, 'Resume this task after review feedback.');

    expect($thread->fresh()->t3_metrics_final_at)->toBeNull()
        ->and($thread->fresh()->t3_metrics_activity_version)->toBe(2)
        ->and($thread->fresh()->t3_metrics_observed_activity_version)->toBeNull();
});

it('does not refinalize a resumed thread from its stored Done state when the command reads Working', function (): void {
    [$thread, $driver, $stream, $dispatcher] = metrics_collection_driver();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $dispatcher->shouldReceive('dispatch')->once()->andReturn(['sequence' => 1, 'thread_id' => 'metrics-thread-1']);
    $stream->shouldReceive('events')->once()->andReturn([[
        'kind' => 'snapshot',
        'snapshot' => [
            'thread' => ['id' => $thread->external_id, 'session' => ['status' => 'running']],
            'snapshotSequence' => 2,
        ],
    ]]);

    $driver->send($thread, 'Resume after review findings.');
    Artisan::call('tasks:collect-t3-metrics');

    expect($thread->fresh()->state)->toBe(AgentThreadState::Done)
        ->and($thread->fresh()->t3_metrics_observed_activity_version)->toBeNull()
        ->and($thread->fresh()->t3_metrics_final_at)->toBeNull()
        ->and($thread->fresh()->t3_metrics_collected_at)->not->toBeNull();
});

it('skips collection while a real T3 send is in flight', function (): void {
    [$thread, $driver, $stream, $dispatcher] = metrics_collection_driver();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $stream->shouldNotReceive('events');
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(static function () use ($thread) {
        expect(AgentThreadSendLease::query()->where('agent_thread_id', $thread->id)->count())->toBe(1);
        Artisan::call('tasks:collect-t3-metrics');

        return ['sequence' => 1, 'thread_id' => 'metrics-thread-1'];
    });

    $driver->send($thread, 'Resume while collector is scheduled.');

    expect(AgentThreadSendLease::query()->where('agent_thread_id', $thread->id)->count())->toBe(0)
        ->and($thread->fresh()->t3_metrics_final_at)->toBeNull()
        ->and($thread->fresh()->t3_metrics_activity_version)->toBe(2);
});

it('rejects a stale collection that began before a real T3 send', function (): void {
    [$thread, $driver, $stream, $dispatcher] = metrics_collection_driver();
    $thread->update(['t3_metrics_final_at' => null]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $dispatcher->shouldReceive('dispatch')->once()->andReturn(['sequence' => 1, 'thread_id' => 'metrics-thread-1']);
    $stream->shouldReceive('events')->once()->andReturnUsing(static function () use ($thread, $driver): iterable {
        $driver->send($thread->fresh(), 'Resume while a stale collection is running.');
        yield [
            'kind' => 'snapshot',
            'snapshot' => [
                'thread' => ['id' => $thread->external_id, 'session' => ['status' => 'done']],
                'snapshotSequence' => 2,
            ],
        ];
    });

    Artisan::call('tasks:collect-t3-metrics');

    expect($thread->fresh()->t3_metrics_activity_version)->toBe(2)
        ->and($thread->fresh()->t3_metrics_final_at)->toBeNull();
});

it('recovers an abandoned send lease so observation and collection resume after expiry', function (): void {
    [$thread] = metrics_collection_driver();
    $thread->update(['t3_metrics_final_at' => null]);
    app(TaskExtensionState::class)->enable();
    $leases = new T3SendLeaseManager;
    $leases->acquire($thread);
    $thread->refresh();
    $observation = new AgentObservation(AgentThreadState::Working);
    $driver = Mockery::mock(AgentDriver::class, AgentMetricCollector::class);
    $driver->shouldReceive('key')->andReturn('t3');
    $driver->shouldReceive('observe')->twice()->andReturn($observation, $observation);
    $driver->shouldReceive('collectMetrics')->once()->andReturn(true);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $observer = new AgentThreadObserver(app(AgentDriverRegistry::class));

    expect($observer->observe($thread))->toBeNull();
    Artisan::call('tasks:collect-t3-metrics');
    expect($thread->fresh()->t3_metrics_collected_at)->toBeNull();

    $now = now();
    Carbon::setTestNow($now->copy()->addSeconds(T3SendLeaseManager::LeaseSeconds + 1));
    try {
        expect($observer->observe($thread))->toEqual($observation);
        Artisan::call('tasks:collect-t3-metrics');
        expect($thread->fresh()->state)->toBe(AgentThreadState::Working)
            ->and($thread->fresh()->t3_metrics_collected_at)->not->toBeNull()
            ->and(AgentThreadSendLease::query()->where('agent_thread_id', $thread->id)->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not let a late old sender release a newer send lease', function (): void {
    [$thread] = metrics_collection_driver();
    $leases = new T3SendLeaseManager;
    $oldToken = $leases->acquire($thread);
    $newToken = $leases->acquire($thread);

    $leases->release($thread, $oldToken);

    expect(AgentThreadSendLease::query()->where('agent_thread_id', $thread->id)->where('owner_token', $newToken)->exists())->toBeTrue()
        ->and($thread->fresh()->sendLeases()->where('expires_at', '>', now())->exists())->toBeTrue();

    $leases->release($thread, $newToken);

    expect($thread->fresh()->sendLeases()->where('expires_at', '>', now())->exists())->toBeFalse();
});
