<?php

declare(strict_types=1);

use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\Support\Schedules\FakeScheduleRuntimeManager;

beforeEach(function (): void {
    $this->gateway = schedules_api_node('gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->targetNode = schedules_api_node('target', '10.44.0.3');
    $this->foreignNode = schedules_api_node('foreign', '10.44.0.4');
    $this->instance = schedules_api_instance($this->targetNode);
    $this->runtime = new FakeScheduleRuntimeManager;

    app()->instance(ScheduleRuntimeManager::class, $this->runtime);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('exposes exactly eight UUID-keyed Schedule routes and returns 404 for unknown identities', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'schedule:'))
        ->mapWithKeys(static fn (RoutingRoute $route): array => [
            $route->getName() => [
                'methods' => $route->methods(),
                'uri' => $route->uri(),
            ],
        ])
        ->sortKeys();

    expect($routes->all())->toBe([
        'schedule:activate' => ['methods' => ['POST'], 'uri' => 'api/v1/schedules/{schedule}/activate'],
        'schedule:add' => ['methods' => ['POST'], 'uri' => 'api/v1/schedules'],
        'schedule:complete' => ['methods' => ['POST'], 'uri' => 'api/v1/schedules/{schedule}/complete'],
        'schedule:list' => ['methods' => ['GET', 'HEAD'], 'uri' => 'api/v1/schedules'],
        'schedule:logs' => ['methods' => ['GET', 'HEAD'], 'uri' => 'api/v1/schedules/{schedule}/logs'],
        'schedule:remove' => ['methods' => ['DELETE'], 'uri' => 'api/v1/schedules/{schedule}'],
        'schedule:run' => ['methods' => ['POST'], 'uri' => 'api/v1/schedules/{schedule}/run'],
        'schedule:show' => ['methods' => ['GET', 'HEAD'], 'uri' => 'api/v1/schedules/{schedule}'],
    ]);

    $unknown = (string) Str::uuid();

    $this->getJson("/api/v1/schedules/{$unknown}")->assertNotFound();
    $this->postJson("/api/v1/schedules/{$unknown}/complete", ['status' => 'success'])->assertNotFound();
    $this->getJson('/api/v1/schedules/123')->assertNotFound();
});

it('adds lists shows and runs bounded Schedule data without changing disabled timer intent', function (): void {
    $payload = schedules_api_payload($this->instance, start: false);
    $created = $this->postJson('/api/v1/schedules', $payload)->assertCreated();
    $scheduleId = $created->json('data.id');

    expect($scheduleId)->toBeString()->and(Str::isUuid($scheduleId))->toBeTrue();
    $created
        ->assertHeader('X-Orbit-Request-Id')
        ->assertJsonPath('data.target_type', 'instance')
        ->assertJsonPath('data.target_id', $this->instance->id)
        ->assertJsonPath('data.desired_timer_state', 'disabled')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.command', 'php artisan report:send')
        ->assertJsonStructure(['meta' => ['request_id']]);

    expect(array_keys($created->json('data')))->toBe([
        'id',
        'target_type',
        'target_id',
        'name',
        'calendar',
        'command',
        'timeout_seconds',
        'desired_timer_state',
        'status',
        'failed_step',
        'error_code',
        'last_run_at',
        'last_run_status',
    ]);

    $this->postJson('/api/v1/schedules', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $scheduleId)
        ->assertJsonPath('data.desired_timer_state', 'disabled');

    $listed = $this->getJson('/api/v1/schedules')->assertOk()->assertJsonCount(1, 'data');
    $listed
        ->assertJsonPath('data.0.id', $scheduleId)
        ->assertJsonPath('data.0.desired_timer_state', 'disabled')
        ->assertJsonMissingPath('data.0.command');
    expect(array_keys($listed->json('data.0')))->toBe([
        'id',
        'target_type',
        'target_id',
        'name',
        'calendar',
        'timeout_seconds',
        'desired_timer_state',
        'status',
        'failed_step',
        'error_code',
        'last_run_at',
        'last_run_status',
    ]);

    $this->getJson("/api/v1/schedules/{$scheduleId}")
        ->assertOk()
        ->assertJsonPath('data.command', 'php artisan report:send');
    $this->postJson("/api/v1/schedules/{$scheduleId}/run")
        ->assertOk()
        ->assertJsonPath('data.desired_timer_state', 'disabled');

    expect($this->runtime->installed)->toBe([$scheduleId, $scheduleId])
        ->and($this->runtime->ran)->toBe([$scheduleId])
        ->and(Schedule::query()->findOrFail($scheduleId)->desired_timer_state)
        ->toBe(DesiredTimerState::Disabled);
});

it('activates an AppInstance Schedule from an empty body idempotently and rejects invalid Node requests', function (): void {
    $schedule = schedules_api_record($this->instance, $this->targetNode, start: false);

    $this->postJson("/api/v1/schedules/{$schedule->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.desired_timer_state', 'enabled');
    $this->postJson("/api/v1/schedules/{$schedule->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.desired_timer_state', 'enabled');

    expect($this->runtime->activated)->toBe([$schedule->id, $schedule->id]);

    $nodeSchedule = schedules_api_record($this->targetNode, $this->targetNode, name: 'node-health');

    $this->postJson("/api/v1/schedules/{$nodeSchedule->id}/activate")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schedule.target_invalid');

    $this->postJson('/api/v1/schedules', schedules_api_payload($this->targetNode, start: false))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'schedule.state_invalid');

    expect(Schedule::query()->where('name', 'daily-report')->count())->toBe(1);
});

it('rejects malformed duplicate escaped-duplicate unknown and wrongly typed add members without mutation', function (
    string $json,
    ?string $sentinel = null,
): void {
    $response = $this
        ->call('POST', '/api/v1/schedules', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertHeader('X-Orbit-Request-Id');

    expect(Schedule::query()->count())->toBe(0)
        ->and($this->runtime->installed)->toBeEmpty()
        ->and(Activity::query()->count())->toBe(1);

    if ($sentinel !== null) {
        $activity = Activity::query()->sole();

        expect($response->getContent())->not->toContain($sentinel)
            ->and(json_encode($activity->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($sentinel);
    }
})->with([
    'malformed object' => ['{"target_type":"instance"'],
    'duplicate member' => ['{"target_type":"instance","target_type":"node","target_id":1,"name":"daily","calendar":"daily","command":"true"}'],
    'escaped duplicate member' => ['{"target_type":"instance","target_t\u0079pe":"node","target_id":1,"name":"daily","calendar":"daily","command":"true"}'],
    'unknown member' => [
        '{"target_type":"instance","target_id":1,"name":"daily","calendar":"daily","command":"true","private":"unknown-member-sentinel"}',
        'unknown-member-sentinel',
    ],
    'wrong member types' => ['{"target_type":[],"target_id":"1","name":42,"calendar":false,"command":{},"timeout_seconds":"3600","start":0}'],
]);

it('requires an active peer and explicit Node access for every Schedule operation', function (): void {
    $schedule = schedules_api_record($this->instance, $this->targetNode, start: false);
    $consumer = schedules_api_node('consumer', '10.44.0.8');
    $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip]);

    $responses = [
        $this->getJson('/api/v1/schedules'),
        $this->postJson('/api/v1/schedules', schedules_api_payload($this->instance)),
        $this->getJson("/api/v1/schedules/{$schedule->id}"),
        $this->postJson("/api/v1/schedules/{$schedule->id}/run"),
        $this->getJson("/api/v1/schedules/{$schedule->id}/logs"),
        $this->postJson("/api/v1/schedules/{$schedule->id}/complete", ['status' => 'success']),
        $this->deleteJson("/api/v1/schedules/{$schedule->id}"),
        $this->postJson("/api/v1/schedules/{$schedule->id}/activate"),
    ];

    foreach ($responses as $response) {
        $response->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    }

    expect($this->runtime->installed)->toBeEmpty()
        ->and($this->runtime->ran)->toBeEmpty()
        ->and($this->runtime->removed)->toBeEmpty()
        ->and($schedule->refresh()->last_run_at)->toBeNull();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson('/api/v1/schedules')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'peer.identity_unknown');
});

it('filters the collection to Schedules on Nodes the caller may address', function (): void {
    $consumer = schedules_api_node('consumer', '10.44.0.8');
    $consumer->accessibleNodes()->attach($this->targetNode->id);
    $visible = schedules_api_record($this->instance, $this->targetNode, name: 'visible');
    $targetVisible = schedules_api_record($this->targetNode, $this->foreignNode, name: 'target-visible');
    $hidden = schedules_api_record($this->foreignNode, $this->foreignNode, name: 'hidden');

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->getJson('/api/v1/schedules')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $targetVisible->id)
        ->assertJsonPath('data.1.id', $visible->id)
        ->assertJsonMissing(['hidden']);

    expect($response->getContent())->not->toContain($hidden->id);
});

it('records seven sanitized operator Activities and no completion Activity', function (): void {
    $payload = schedules_api_payload(
        $this->instance,
        command: 'command-private-sentinel /private/path --user=private-user --unit=private-unit',
        calendar: 'calendar-private-sentinel',
        start: false,
    );

    $this->getJson('/api/v1/schedules')->assertOk();
    $created = $this->postJson('/api/v1/schedules', $payload)->assertCreated();
    $scheduleId = $created->json('data.id');
    $this->getJson("/api/v1/schedules/{$scheduleId}")->assertOk();
    $this->postJson("/api/v1/schedules/{$scheduleId}/run")->assertOk();
    $this->getJson("/api/v1/schedules/{$scheduleId}/logs?lines=25")->assertOk();
    $this->postJson("/api/v1/schedules/{$scheduleId}/activate")->assertOk();

    $beforeCompletion = Activity::query()->count();
    $this->withServerVariables(['REMOTE_ADDR' => $this->targetNode->wireguard_ip])
        ->postJson("/api/v1/schedules/{$scheduleId}/complete", ['status' => 'success'])
        ->assertNoContent();
    expect(Activity::query()->count())->toBe($beforeCompletion);

    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
        ->deleteJson("/api/v1/schedules/{$scheduleId}")
        ->assertOk();

    $activities = Activity::query()->orderBy('id')->get();
    expect($activities->pluck('command')->all())->toBe([
        'schedule:list',
        'schedule:add',
        'schedule:show',
        'schedule:run',
        'schedule:logs',
        'schedule:activate',
        'schedule:remove',
    ]);

    $activityJson = json_encode($activities->map->getAttributes()->all(), JSON_THROW_ON_ERROR);
    expect($activityJson)
        ->not->toContain('command-private-sentinel')
        ->not->toContain('calendar-private-sentinel')
        ->not->toContain('/private/path')
        ->not->toContain('private-user')
        ->not->toContain('private-unit')
        ->not->toContain('safe\\n');

    foreach ($activities->where('command', '!=', 'schedule:list') as $activity) {
        expect(data_get($activity->properties, 'schedule.id'))->toBe($scheduleId)
            ->and($activity->subject_type)->toBe(AppInstance::class)
            ->and($activity->subject_id)->toBe($this->instance->id)
            ->and($activity->target_node_id)->toBe($this->targetNode->id);
    }
});

it('returns bounded logs and validates all Schedule operation input before mutation', function (): void {
    $schedule = schedules_api_record($this->instance, $this->targetNode, start: false);

    $this->getJson("/api/v1/schedules/{$schedule->id}/logs?lines=25")
        ->assertOk()
        ->assertJsonPath('data.id', $schedule->id)
        ->assertJsonPath('data.lines', 25)
        ->assertJsonPath('data.output', "safe\n")
        ->assertJsonPath('data.truncated', false)
        ->assertJsonStructure(['meta' => ['request_id']]);

    foreach ([
        $this->getJson('/api/v1/schedules?unknown=value'),
        $this->getJson("/api/v1/schedules/{$schedule->id}?unknown=value"),
        $this->getJson("/api/v1/schedules/{$schedule->id}/logs?lines[]=25"),
        $this->getJson("/api/v1/schedules/{$schedule->id}/logs?unknown=value"),
        $this->postJson("/api/v1/schedules/{$schedule->id}/run", ['unknown' => true]),
        $this->postJson("/api/v1/schedules/{$schedule->id}/activate", ['unknown' => true]),
        $this->call(
            'DELETE',
            "/api/v1/schedules/{$schedule->id}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"unknown":true}',
        ),
    ] as $response) {
        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    }

    expect($this->runtime->ran)->toBeEmpty()
        ->and($this->runtime->activated)->toBeEmpty()
        ->and($this->runtime->removed)->toBeEmpty()
        ->and(Schedule::query()->whereKey($schedule->id)->exists())->toBeTrue();
});

function schedules_api_node(string $name, string $ip): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.match ($name) {
            'gateway' => '10',
            'target' => '20',
            'foreign' => '30',
            default => '40',
        },
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $ip,
    ]);
}

function schedules_api_instance(Node $node): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'Reports',
        'slug' => 'reports',
        'repository_url' => 'git@example.test:reports.git',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/reports',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

function schedules_api_record(
    Node|AppInstance $target,
    Node $host,
    string $name = 'daily-report',
    bool $start = true,
): Schedule {
    return Schedule::query()->create([
        'target_type' => $target::class,
        'target_id' => $target->id,
        'host_node_id' => $host->id,
        'name' => $name,
        'calendar' => 'daily',
        'command' => 'php artisan report:send',
        'timeout_seconds' => 3600,
        'desired_timer_state' => $start ? DesiredTimerState::Enabled : DesiredTimerState::Disabled,
        'status' => LifecycleStatus::Active,
    ]);
}

/** @return array<string, mixed> */
function schedules_api_payload(
    Node|AppInstance $target,
    string $command = 'php artisan report:send',
    string $calendar = 'daily',
    bool $start = true,
): array {
    return [
        'target_type' => $target instanceof Node ? 'node' : 'instance',
        'target_id' => $target->id,
        'name' => 'daily-report',
        'calendar' => $calendar,
        'command' => $command,
        'timeout_seconds' => 3600,
        'start' => $start,
    ];
}
