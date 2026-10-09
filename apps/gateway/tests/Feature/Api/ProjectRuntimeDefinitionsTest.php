<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ScheduleDefinition;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->orbitApp = runtime_definition_app('acme');
});

describe('ProcessUser', function (): void {
    it('refuses an account in a Project definition on create and replace', function (): void {
        $payload = runtime_definition_process_payload('worker', '/usr/bin/sleep');
        $this->postJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions", $payload)->assertCreated();
        $payload['spec']['user'] = 'orbit-worker';
        $this->postJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions", $payload)->assertUnprocessable();
        $this->putJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions/worker", $payload)->assertUnprocessable();
        expect(ProcessDefinition::query()->sole()->spec)->not->toHaveKey('user');
    });
});

it('provides complete process definition CRUD with command-safe collections', function (): void {
    $command = '/usr/bin/php-command-sentinel';
    $created = $this
        ->postJson(
            "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
            runtime_definition_process_payload('worker', $command),
        )
        ->assertCreated()
        ->assertJsonPath('data.project_id', $this->orbitApp->id)
        ->assertJsonPath('data.name', 'worker')
        ->assertJsonPath('data.environments', ['production'])
        ->assertJsonPath('data.spec.runtime', 'systemd')
        ->assertJsonPath('data.spec.command.0', $command)
        ->assertJsonPath('data.spec.restart_policy', 'on-failure')
        ->assertJsonPath('data.spec.keep_alive', true);
    $id = $created->json('data.id');

    expect($id)->toBeString()->and(Str::isUuid($id))->toBeTrue();

    $listed = $this
        ->getJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions")
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonMissingPath('data.0.spec.command');
    expect($listed->getContent())->not->toContain($command);

    $this
        ->getJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions/worker")
        ->assertOk()
        ->assertJsonPath('data.spec.command.0', $command);

    $replacement = runtime_definition_process_payload('web', '/usr/bin/new-command');
    $replacement['environments'] = ['production'];
    $this
        ->putJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions/worker", $replacement)
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.name', 'web')
        ->assertJsonPath('data.environments', ['production'])
        ->assertJsonPath('data.spec.command.0', '/usr/bin/new-command');

    $stored = ProcessDefinition::query()->sole();
    expect($stored->name)
        ->toBe('web')
        ->and($stored->environments)
        ->toBe(['production'])
        ->and($stored->spec)
        ->toMatchArray($replacement['spec']);

    $this
        ->deleteJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions/web")
        ->assertOk()
        ->assertJsonPath('data.id', $id);
    expect(ProcessDefinition::query()->count())->toBe(0);
});

it('provides complete Schedule definition CRUD with command-safe collections', function (): void {
    $command = 'php artisan schedule-command-sentinel';
    $created = $this
        ->postJson(
            "/api/v1/projects/{$this->orbitApp->id}/schedule-definitions",
            runtime_definition_schedule_payload('hourly', $command),
        )
        ->assertCreated()
        ->assertJsonPath('data.spec.command', $command)
        ->assertJsonPath('data.spec.calendar', 'hourly')
        ->assertJsonPath('data.spec.timeout_seconds', 3600);
    $id = $created->json('data.id');

    expect($id)->toBeString()->and(Str::isUuid($id))->toBeTrue();
    $listed = $this
        ->getJson("/api/v1/projects/{$this->orbitApp->id}/schedule-definitions")
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.spec.calendar', 'hourly')
        ->assertJsonMissingPath('data.0.spec.command');
    expect($listed->getContent())->not->toContain($command);

    $this
        ->getJson("/api/v1/projects/{$this->orbitApp->id}/schedule-definitions/hourly")
        ->assertOk()
        ->assertJsonPath('data.spec.command', $command);

    $replacement = runtime_definition_schedule_payload('daily', 'php artisan report');
    $replacement['environments'] = ['production'];
    $replacement['spec']['calendar'] = 'daily';
    $replacement['spec']['timeout_seconds'] = 30;
    $this
        ->putJson("/api/v1/projects/{$this->orbitApp->id}/schedule-definitions/hourly", $replacement)
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.name', 'daily')
        ->assertJsonPath('data.environments', ['production'])
        ->assertJsonPath('data.spec.timeout_seconds', 30);

    $this
        ->deleteJson("/api/v1/projects/{$this->orbitApp->id}/schedule-definitions/daily")
        ->assertOk();
    expect(ScheduleDefinition::query()->count())->toBe(0);
});

it('scopes names to one App and definition kind', function (): void {
    $payload = runtime_definition_process_payload('worker');
    $this->postJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions", $payload)->assertCreated();
    $this
        ->postJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions", $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'process_definition.name_taken');

    $otherApp = runtime_definition_app('other');
    $this->postJson("/api/v1/projects/{$otherApp->id}/process-definitions", $payload)->assertCreated();
    $this
        ->postJson(
            "/api/v1/projects/{$this->orbitApp->id}/schedule-definitions",
            runtime_definition_schedule_payload('worker'),
        )
        ->assertCreated();

    expect(ProcessDefinition::query()->where('name', 'worker')->count())
        ->toBe(2)
        ->and(ScheduleDefinition::query()->where('name', 'worker')->count())
        ->toBe(1);
});

it('keeps definition commands out of conflict logs', function (string $kind, string $operation): void {
    $endpoint = "/api/v1/projects/{$this->orbitApp->id}/{$kind}-definitions";
    $payload = $kind === 'process'
        ? runtime_definition_process_payload('taken')
        : runtime_definition_schedule_payload('taken');
    $sentinel = "{$kind}-{$operation}-conflict-command-sentinel";

    $this->postJson($endpoint, $payload)->assertCreated();

    if ($operation === 'update') {
        $target = $kind === 'process'
            ? runtime_definition_process_payload('replacement')
            : runtime_definition_schedule_payload('replacement');
        $this->postJson($endpoint, $target)->assertCreated();
        $endpoint .= '/replacement';
    }

    $payload = $kind === 'process'
        ? runtime_definition_process_payload('taken', "/usr/bin/{$sentinel}")
        : runtime_definition_schedule_payload('taken', $sentinel);
    $logged = [];

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
        $exception = $event->context['exception'] ?? null;

        while ($exception instanceof Throwable) {
            $logged[] = $exception->getMessage();
            $exception = $exception->getPrevious();
        }
    });

    $response = $operation === 'create'
        ? $this->postJson($endpoint, $payload)
        : $this->putJson($endpoint, $payload);

    $response
        ->assertConflict()
        ->assertJsonPath('error.code', "{$kind}_definition.name_taken");

    expect($logged)
        ->toBeEmpty()
        ->and(implode("\n", $logged))
        ->not->toContain($sentinel);
})->with([
    'process create' => ['process', 'create'],
    'process update' => ['process', 'update'],
    'Schedule create' => ['schedule', 'create'],
    'Schedule update' => ['schedule', 'update'],
]);

it('applies Process specification limits to non-JSON content types', function (array $specification): void {
    $body = json_encode([
        'name' => 'worker',
        'environments' => ['production'],
        'spec' => $specification,
    ], JSON_THROW_ON_ERROR);

    $this
        ->call(
            'POST',
            "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
            server: ['CONTENT_TYPE' => 'text/plain'],
            content: $body,
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(ProcessDefinition::query()->count())->toBe(0);
})->with([
    'relative systemd executable' => [[
        'runtime' => 'systemd',
        'command' => ['php'],
    ]],
    'invalid Docker environment name' => [[
        'runtime' => 'docker',
        'command' => ['php'],
        'image' => 'php:8.5',
        'environment' => ['INVALID-NAME' => 'value'],
    ]],
    'out-of-range Docker port' => [[
        'runtime' => 'docker',
        'command' => ['php'],
        'image' => 'php:8.5',
        'ports' => ['0:80'],
    ]],
]);

it('rejects literal dots and asterisks in Docker environment names', function (
    string $contentType,
    string $environmentName,
): void {
    $body = json_encode([
        'name' => 'worker',
        'environments' => ['production'],
        'spec' => [
            'runtime' => 'docker',
            'command' => ['php'],
            'image' => 'php:8.5',
            'environment' => [$environmentName => 'value'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this
        ->call(
            'POST',
            "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
            server: ['CONTENT_TYPE' => $contentType],
            content: $body,
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(ProcessDefinition::query()->count())->toBe(0);
})->with([
    'JSON name containing a dot' => ['application/json', 'A.B'],
    'JSON name containing an asterisk' => ['application/json', 'A*B'],
    'JSON name starting with a dot' => ['application/json', '.A'],
    'plain-text name containing a dot' => ['text/plain', 'A.B'],
    'plain-text name containing an asterisk' => ['text/plain', 'A*B'],
    'plain-text name starting with a dot' => ['text/plain', '.A'],
]);

it('uses the placed App operation boundary and rejects cross-App names', function (): void {
    $this->postJson(
        "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
        runtime_definition_process_payload('worker'),
    )->assertCreated();
    $otherApp = runtime_definition_app('other');

    $this
        ->getJson("/api/v1/projects/{$otherApp->id}/process-definitions/worker")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    $owner = Node::query()->create([
        'name' => 'owner',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $owner->id,
        'name' => 'development',
        'checkout_path' => '/srv/acme',
        'branch' => 'main',
        'status' => 'active',
    ]);
    $caller = Node::query()->create([
        'name' => 'caller',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $caller->accessibleNodes()->attach($owner);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this
        ->getJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions/worker")
        ->assertOk();

    $caller->accessibleNodes()->detach($owner);
    $this
        ->getJson("/api/v1/projects/{$this->orbitApp->id}/process-definitions/worker")
        ->assertForbidden();
});

it('rejects unknown and recursively duplicated JSON members without disclosing commands', function (
    string $body,
): void {
    $requestId = (string) Str::uuid();
    $sentinel = 'definition-secret-command';
    $response = $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->call(
            'POST',
            "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: str_replace('__COMMAND__', $sentinel, $body),
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($response->getContent())
        ->not->toContain($sentinel)
        ->and(print_r(Activity::query()->where('request_id', $requestId)->get()->toArray(), return: true))
        ->not->toContain($sentinel);
})->with([
    'unknown root member' => [
        '{"name":"worker","environments":["production"],"spec":{"runtime":"systemd","command":["__COMMAND__"]},"start":true}',
    ],
    'duplicate root member' => [
        '{"name":"worker","name":"other","environments":["production"],"spec":{"runtime":"systemd","command":["__COMMAND__"]}}',
    ],
    'escaped duplicate nested member' => [
        '{"name":"worker","environments":["production"],"spec":{"runtime":"systemd","command":["__COMMAND__"],"comm\\u0061nd":["other"]}}',
    ],
    'unknown nested member' => [
        '{"name":"worker","environments":["production"],"spec":{"runtime":"systemd","command":["__COMMAND__"],"host_node_id":1}}',
    ],
    'duplicate environment member' => [
        '{"name":"worker","environments":["production"],"spec":{"runtime":"docker","command":["__COMMAND__"],"image":"php:8.5","environment":{"TOKEN":"first","TOKEN":"second"}}}',
    ],
    'duplicate volume member' => [
        '{"name":"worker","environments":["production"],"spec":{"runtime":"docker","command":["__COMMAND__"],"image":"php:8.5","volumes":[{"source":"data","target":"/data","targ\\u0065t":"/other"}]}}',
    ],
]);

it('keeps definition commands out of Activity and model debug output', function (): void {
    $requestId = (string) Str::uuid();
    $sentinel = '/usr/bin/activity-command-sentinel';
    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson(
            "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
            runtime_definition_process_payload('worker', $sentinel),
        )
        ->assertCreated();

    $definition = ProcessDefinition::query()->sole();
    $activity = Activity::query()->where('request_id', $requestId)->sole();

    expect($activity->command)
        ->toBe('project:process-definition:create')
        ->and($activity->subject_type)
        ->toBe(Project::class)
        ->and($activity->subject_id)
        ->toBe($this->orbitApp->id)
        ->and(print_r($activity->toArray(), return: true))
        ->not->toContain($sentinel)
        ->and(print_r($definition, return: true))
        ->not->toContain($sentinel)
        ->and($definition->spec['command'][0])
        ->toBe($sentinel);
});

it('records each definition command name against the App', function (
    string $method,
    string $path,
    ?array $payload,
    string $command,
): void {
    $this->postJson(
        "/api/v1/projects/{$this->orbitApp->id}/process-definitions",
        runtime_definition_process_payload('worker'),
    )->assertCreated();
    $this->postJson(
        "/api/v1/projects/{$this->orbitApp->id}/schedule-definitions",
        runtime_definition_schedule_payload('hourly'),
    )->assertCreated();
    Activity::query()->delete();
    $requestId = (string) Str::uuid();

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->json($method, "/api/v1/projects/{$this->orbitApp->id}/{$path}", $payload ?? [])
        ->assertSuccessful();

    $activity = Activity::query()->where('request_id', $requestId)->sole();

    expect($activity->command)
        ->toBe($command)
        ->and($activity->subject_type)
        ->toBe(Project::class)
        ->and($activity->subject_id)
        ->toBe($this->orbitApp->id);
})->with([
    'process list' => ['GET', 'process-definitions', null, 'project:process-definition:list'],
    'process show' => ['GET', 'process-definitions/worker', null, 'project:process-definition:show'],
    'process update' => ['PUT', 'process-definitions/worker', runtime_definition_process_payload('worker'), 'project:process-definition:update'],
    'process destroy' => ['DELETE', 'process-definitions/worker', null, 'project:process-definition:destroy'],
    'schedule list' => ['GET', 'schedule-definitions', null, 'project:schedule-definition:list'],
    'schedule show' => ['GET', 'schedule-definitions/hourly', null, 'project:schedule-definition:show'],
    'schedule update' => ['PUT', 'schedule-definitions/hourly', runtime_definition_schedule_payload('hourly'), 'project:schedule-definition:update'],
    'schedule destroy' => ['DELETE', 'schedule-definitions/hourly', null, 'project:schedule-definition:destroy'],
]);

function runtime_definition_app(string $slug): Project
{
    return Project::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'repository_url' => "https://example.test/{$slug}.git",
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
}

/** @return array{name: string, environments: list<string>, spec: array<string, mixed>} */
function runtime_definition_process_payload(
    string $name,
    string $command = '/usr/bin/php',
): array {
    return [
        'name' => $name,
        'environments' => ['production'],
        'spec' => [
            'runtime' => 'systemd',
            'command' => [$command, 'artisan', 'queue:work'],
            'working_directory' => '/srv/app',
            'restart_policy' => 'on-failure',
            'keep_alive' => true,
        ],
    ];
}

/** @return array{name: string, environments: list<string>, spec: array<string, mixed>} */
function runtime_definition_schedule_payload(
    string $name,
    string $command = 'php artisan schedule:run',
): array {
    return [
        'name' => $name,
        'environments' => ['production'],
        'spec' => [
            'command' => $command,
            'calendar' => 'hourly',
            'timeout_seconds' => 3600,
        ],
    ];
}
