<?php

declare(strict_types=1);

use App\Data\Activities\ActivityData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Models\Activity;
use App\Models\Node;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * @return list<RecordBroadcast>
 */
function activity_notice_events(): array
{
    $events = [];

    foreach (Event::dispatched(RecordBroadcast::class) as $arguments) {
        $event = $arguments[0] ?? null;

        if ($event instanceof RecordBroadcast && str_starts_with($event->type->value, 'activity.')) {
            $events[] = $event;
        }
    }

    return $events;
}

function activity_notice_running(
    string $command,
    int $ageSeconds = 3600,
    ?int $callerNodeId = null,
    ?int $targetNodeId = null,
    ?string $properties = null,
): int {
    $createdAt = Carbon::now()->subSeconds($ageSeconds);

    return (int) DB::table('activity_log')->insertGetId([
        'log_name' => 'commands',
        'description' => $command,
        'event' => 'command',
        'properties' => $properties,
        'request_id' => (string) Str::uuid(),
        'command' => $command,
        'caller_node_id' => $callerNodeId,
        'target_node_id' => $targetNodeId,
        'status' => 'running',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function activity_notice_reverb_fails(): void
{
    activate_websocket_role();

    Broadcast::extend('reverb', function (): Broadcaster {
        return new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new RuntimeException('Reverb is unreachable.');
            }
        };
    });
}

describe('activity record events', function (): void {
    beforeEach(function (): void {
        Route::middleware('api')->group(function (): void {
            Route::post('/api/v1/activity-notices/mutate', fn () => response()->json(['ok' => true]))
                ->name('process:record');
            Route::get('/api/v1/activity-notices/read', fn () => response()->json(['ok' => true]))
                ->name('node:record');
            Route::post('/api/v1/activity-notices/self', fn () => response()->json(['ok' => true]))
                ->name('activity:record');
            Route::get('/api/v1/activity-notices/self-read', fn () => response()->json(['ok' => true]))
                ->name('activity:record-read');
        });
        Route::getRoutes()->refreshNameLookups();
    });

    it('broadcasts activity.created and activity.updated when a running activity succeeds', function (): void {
        $caller = Node::query()->create([
            'name' => 'activity-caller',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.158',
            'wireguard_ip' => '10.44.0.158',
        ]);
        $requestId = '01999999-0000-7000-8000-000000000158';
        $marker = 'orbit-activity-property-marker';

        Event::fake([RecordBroadcast::class]);

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/activity-notices/mutate', ['note' => $marker])
            ->assertOk();

        $activity = Activity::query()->where('request_id', $requestId)->sole();
        $events = activity_notice_events();
        $occurredAt = ActivityData::fromModel($activity)->occurredAt;
        $created = [
            'id' => $activity->id,
            'request_id' => $requestId,
            'command' => 'process:record',
            'status' => 'running',
            'caller_node_id' => $caller->id,
            'target_node_id' => null,
            'error_code' => null,
            'duration_ms' => null,
            'occurred_at' => $occurredAt,
        ];

        expect($activity->status)->toBe('succeeded')
            ->and($activity->properties?->toJson())->toContain($marker)
            ->and($events)->toHaveCount(2)
            ->and($events[0]->type)->toBe(RecordEventType::ActivityCreated)
            ->and($events[0]->id)->toBe($activity->id)
            ->and($events[0]->data)->toBe($created)
            ->and($events[0]->broadcastOn()[0]->name)->toBe('private-orbit')
            ->and($events[1]->type)->toBe(RecordEventType::ActivityUpdated)
            ->and($events[1]->id)->toBe($activity->id)
            ->and($events[1]->data)->toBe([
                ...$created,
                'status' => 'succeeded',
                'duration_ms' => $activity->duration_ms,
            ])
            ->and($events[1]->broadcastOn()[0]->name)->toBe('private-orbit');

        foreach ($events as $event) {
            $payload = json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR);

            expect($payload)->not->toContain($marker)
                ->and(strlen($payload))->toBeLessThan(2_000);
        }
    });

    it('broadcasts activity.created only when a sampled read stores an activity', function (): void {
        Event::fake([RecordBroadcast::class]);

        $this->getJson('/api/v1/activity-notices/read')->assertOk();

        $activity = Activity::query()->where('command', 'node:record')->sole();
        $events = activity_notice_events();

        expect($activity->properties?->get('path'))->toBe('api/v1/activity-notices/read')
            ->and($events)->toHaveCount(1)
            ->and($events[0]->type)->toBe(RecordEventType::ActivityCreated)
            ->and($events[0]->data)->toBe([
                'id' => $activity->id,
                'request_id' => $activity->request_id,
                'command' => 'node:record',
                'status' => 'succeeded',
                'caller_node_id' => null,
                'target_node_id' => null,
                'error_code' => null,
                'duration_ms' => $activity->duration_ms,
                'occurred_at' => ActivityData::fromModel($activity)->occurredAt,
            ])
            ->and(json_encode($events[0]->broadcastWith(), JSON_THROW_ON_ERROR))->not->toContain('activity-notices');

        $this->getJson('/api/v1/activity-notices/read')->assertOk();

        expect(Activity::query()->where('command', 'node:record')->count())->toBe(1)
            ->and(activity_notice_events())->toHaveCount(1);
    });

    it('does not broadcast an activity notice for an activity command', function (): void {
        Event::fake([RecordBroadcast::class]);

        $this->postJson('/api/v1/activity-notices/self')->assertOk();
        $this->getJson('/api/v1/activity-notices/self-read')->assertOk();

        expect(Activity::query()->where('command', 'activity:record')->sole()->status)->toBe('succeeded')
            ->and(Activity::query()->where('command', 'activity:record-read')->sole()->status)->toBe('succeeded')
            ->and(activity_notice_events())->toBe([]);
    });

    it('broadcasts activity.updated when the interrupted activity sweep ends a running row', function (): void {
        $caller = Node::query()->create([
            'name' => 'activity-sweep-caller',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.181',
            'wireguard_ip' => '10.44.0.181',
        ]);
        $target = Node::query()->create([
            'name' => 'activity-sweep-target',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.182',
            'wireguard_ip' => '10.44.0.182',
        ]);
        $marker = 'orbit-activity-properties-must-stay-off-the-notice';
        $properties = json_encode(['input' => $marker.str_repeat('y', 12_000)], JSON_THROW_ON_ERROR);
        $id = activity_notice_running('instance:deploy', callerNodeId: $caller->id, targetNodeId: $target->id, properties: $properties);
        $omitted = activity_notice_running('activity:list');

        Event::fake([RecordBroadcast::class]);

        $this->artisan('orbit:activity-finalize-interrupted')->assertSuccessful();

        $activity = Activity::query()->findOrFail($id);
        $events = activity_notice_events();
        $stored = DB::table('activity_log')->where('id', $id)->value('properties');

        expect($events)->toHaveCount(1);

        $payload = json_encode($events[0]->broadcastWith(), JSON_THROW_ON_ERROR);

        expect(is_string($stored) ? $stored : json_encode($stored))->toContain($marker)
            ->and(Activity::query()->findOrFail($omitted)->status)->toBe('failed')
            ->and($events[0]->type)->toBe(RecordEventType::ActivityUpdated)
            ->and($events[0]->id)->toBe($id)
            ->and($events[0]->data)->toBe([
                'id' => $id,
                'request_id' => $activity->request_id,
                'command' => 'instance:deploy',
                'status' => 'failed',
                'caller_node_id' => $caller->id,
                'target_node_id' => $target->id,
                'error_code' => 'activity.interrupted',
                'duration_ms' => null,
                'occurred_at' => ActivityData::fromModel($activity)->occurredAt,
            ])
            ->and($payload)->not->toContain($marker)
            ->and(strlen($payload))->toBeLessThan(2_000);
    });

    it('broadcasts activity.updated when the shutdown finalizer ends a running activity', function (): void {
        $id = activity_notice_running('instance:register', ageSeconds: 1);
        $activity = Activity::query()->findOrFail($id);

        Event::fake([RecordBroadcast::class]);

        ActivityShutdownFinalizer::arm($activity)->finalize();

        $activity->refresh();
        $events = activity_notice_events();

        expect($activity->status)->toBe('failed')
            ->and($activity->error_code)->toBe('activity.interrupted')
            ->and($events)->toHaveCount(1)
            ->and($events[0]->type)->toBe(RecordEventType::ActivityUpdated)
            ->and($events[0]->id)->toBe($id)
            ->and($events[0]->data['status'])->toBe('failed')
            ->and($events[0]->data['error_code'])->toBe('activity.interrupted')
            ->and($events[0]->data['duration_ms'])->toBeNull()
            ->and(array_key_exists('properties', $events[0]->data))->toBeFalse();
    });

    it('does not broadcast an activity notice when a write changes only activity properties', function (): void {
        Event::fake([RecordBroadcast::class]);
        $activity = Activity::query()->create([
            'log_name' => 'commands',
            'description' => 'process:record',
            'event' => 'command',
            'request_id' => (string) Str::uuid(),
            'command' => 'process:record',
            'status' => 'succeeded',
            'properties' => ['note' => 'before'],
        ]);

        Event::fake([RecordBroadcast::class]);

        $activity->update(['properties' => ['note' => 'orbit-activity-property-marker']]);

        expect($activity->fresh()?->properties?->toJson())->toContain('orbit-activity-property-marker')
            ->and(activity_notice_events())->toBe([]);
    });

    it('does not broadcast an activity notice when the activity write rolls back', function (): void {
        Event::fake([RecordBroadcast::class]);

        DB::beginTransaction();
        $this->postJson('/api/v1/activity-notices/mutate')->assertOk();
        DB::rollBack();

        expect(Activity::query()->where('command', 'process:record')->count())->toBe(0)
            ->and(activity_notice_events())->toBe([]);
    });

    it('keeps the saved activity outcome when broadcasting an activity notice fails', function (): void {
        activity_notice_reverb_fails();
        Log::spy();

        $this->postJson('/api/v1/activity-notices/mutate')->assertOk();

        expect(Activity::query()->where('command', 'process:record')->sole()->status)->toBe('succeeded');
        Log::shouldHaveReceived('warning')
            ->with('Failed to broadcast a record event.', Mockery::type('array'))
            ->twice();
    });

    it('keeps the interrupted activity outcome when broadcasting the sweep notice fails', function (): void {
        $id = activity_notice_running('instance:deploy');
        activity_notice_reverb_fails();
        Log::spy();

        $this->artisan('orbit:activity-finalize-interrupted')->assertSuccessful();

        expect(Activity::query()->findOrFail($id)->status)->toBe('failed')
            ->and(Activity::query()->findOrFail($id)->error_code)->toBe('activity.interrupted');
        Log::shouldHaveReceived('warning')
            ->with('Failed to broadcast a record event.', Mockery::type('array'))
            ->once();
    });
});
