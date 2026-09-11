<?php

declare(strict_types=1);

use App\Actions\AppDefinitions\CreateProcessDefinitionAction;
use App\Actions\AppDefinitions\CreateScheduleDefinitionAction;
use App\Actions\AppDefinitions\RemoveProcessDefinitionAction;
use App\Actions\AppDefinitions\ReplaceProcessDefinitionAction;
use App\Actions\Apps\RemoveAppAction;
use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->orbitApp = domain_runtime_definition_app('acme');
});

it('accepts complete systemd, Docker, and Schedule definition specifications', function (): void {
    $this
        ->postJson("/api/v1/apps/{$this->orbitApp->id}/process-definitions", [
            'name' => 'systemd-worker',
            'environments' => ['development', 'production'],
            'spec' => [
                'runtime' => 'systemd',
                'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
                'working_directory' => '/srv/app',
                'restart_policy' => 'always',
            ],
        ])
        ->assertCreated();
    $this
        ->postJson("/api/v1/apps/{$this->orbitApp->id}/process-definitions", [
            'name' => 'docker-worker',
            'environments' => ['production'],
            'spec' => [
                'runtime' => 'docker',
                'image' => 'registry.example.test/acme/worker:latest',
                'command' => ['php', 'artisan', 'queue:work'],
                'working_directory' => '/app',
                'environment' => ['QUEUE' => 'primary'],
                'ports' => ['127.0.0.1:8080:80/tcp'],
                'volumes' => [['source' => 'data', 'target' => '/data', 'read_only' => true]],
                'restart_policy' => 'unless-stopped',
            ],
        ])
        ->assertCreated();
    $this
        ->postJson("/api/v1/apps/{$this->orbitApp->id}/schedule-definitions", [
            'name' => 'report',
            'environments' => ['development'],
            'spec' => [
                'command' => 'php artisan report',
                'calendar' => '*-*-* 03:00:00',
                'timeout_seconds' => 86400,
            ],
        ])
        ->assertCreated();

    expect(ProcessDefinition::query()->count())
        ->toBe(2)
        ->and(ScheduleDefinition::query()->count())
        ->toBe(1);
});

it('rejects invalid applicability and runtime specification boundaries', function (
    string $kind,
    array $payload,
): void {
    $this
        ->postJson("/api/v1/apps/{$this->orbitApp->id}/{$kind}-definitions", $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
})->with([
    'empty applicability' => ['process', domain_process_definition_payload(environments: [])],
    'duplicate applicability' => [
        'process',
        domain_process_definition_payload(environments: ['development', 'development']),
    ],
    'unknown applicability' => ['process', domain_process_definition_payload(environments: ['staging'])],
    'relative systemd executable' => [
        'process',
        domain_process_definition_payload(spec: ['runtime' => 'systemd', 'command' => ['php']]),
    ],
    'process target identity' => [
        'process',
        domain_process_definition_payload(spec: [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php'],
            'target_id' => 1,
        ]),
    ],
    'process initial state' => [
        'process',
        domain_process_definition_payload(spec: [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php'],
            'start' => true,
        ]),
    ],
    'Docker image omitted' => [
        'process',
        domain_process_definition_payload(spec: ['runtime' => 'docker', 'command' => ['php']]),
    ],
    'Docker fields on systemd' => [
        'process',
        domain_process_definition_payload(spec: [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php'],
            'image' => 'php:8.5',
        ]),
    ],
    'invalid published port' => [
        'process',
        domain_process_definition_payload(spec: [
            'runtime' => 'docker',
            'command' => ['php'],
            'image' => 'php:8.5',
            'ports' => ['70000:80'],
        ]),
    ],
    'multiline Schedule command' => [
        'schedule',
        domain_schedule_definition_payload(spec: [
            'command' => "php artisan report\nnext",
            'calendar' => 'daily',
            'timeout_seconds' => 60,
        ]),
    ],
    'non-printable Schedule calendar' => [
        'schedule',
        domain_schedule_definition_payload(spec: [
            'command' => 'php artisan report',
            'calendar' => "daily\n",
            'timeout_seconds' => 60,
        ]),
    ],
    'Schedule timeout below bound' => [
        'schedule',
        domain_schedule_definition_payload(spec: [
            'command' => 'php artisan report',
            'calendar' => 'daily',
            'timeout_seconds' => 0,
        ]),
    ],
    'Schedule timeout above bound' => [
        'schedule',
        domain_schedule_definition_payload(spec: [
            'command' => 'php artisan report',
            'calendar' => 'daily',
            'timeout_seconds' => 86401,
        ]),
    ],
]);

it('keeps definition mutations database-only and preserves existing runtime state', function (): void {
    $instance = domain_runtime_definition_instance($this->orbitApp, $this->gateway, 'primary');
    $other = domain_runtime_definition_instance($this->orbitApp, $this->gateway, 'other');
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => 'existing-worker',
        'runtime' => 'systemd',
        'working_directory' => '/srv/app',
        'runtime_config' => ['command' => ['/usr/bin/php']],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => 'active',
    ]);
    $schedule = Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $instance->id,
        'host_node_id' => $this->gateway->id,
        'name' => 'existing-report',
        'calendar' => 'daily',
        'command' => 'php artisan report',
        'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled',
        'status' => 'active',
    ]);
    $runtimeBefore = [
        'instance' => $instance->fresh()->toArray(),
        'other' => $other->fresh()->toArray(),
        'process' => $process->fresh()->getAttributes(),
        'schedule' => $schedule->fresh()->getAttributes(),
    ];

    $this->app->bind(ProcessRuntimeManager::class, static fn (): never => throw new RuntimeException('remote process call'));
    $this->app->bind(ScheduleRuntimeManager::class, static fn (): never => throw new RuntimeException('remote Schedule call'));

    $processDefinition = app(CreateProcessDefinitionAction::class)->execute(
        $this->orbitApp,
        new AppDefinitionInputData('worker', ['development'], [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php'],
        ]),
    );
    $scheduleDefinition = app(CreateScheduleDefinitionAction::class)->execute(
        $this->orbitApp,
        new AppDefinitionInputData('report', ['production'], [
            'command' => 'php artisan report',
            'calendar' => 'daily',
            'timeout_seconds' => 60,
        ]),
    );
    app(ReplaceProcessDefinitionAction::class)->execute(
        $processDefinition,
        new AppDefinitionInputData('worker-next', ['production'], [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php', 'artisan'],
        ]),
    );
    app(RemoveProcessDefinitionAction::class)->execute($processDefinition);

    expect($instance->fresh()->toArray())
        ->toBe($runtimeBefore['instance'])
        ->and($other->fresh()->toArray())
        ->toBe($runtimeBefore['other'])
        ->and($process->fresh()->getAttributes())
        ->toBe($runtimeBefore['process'])
        ->and($schedule->fresh()->getAttributes())
        ->toBe($runtimeBefore['schedule'])
        ->and($scheduleDefinition->fresh())
        ->not->toBeNull();
});

it('keeps definitions when an AppInstance is removed and keeps unrelated copies independent', function (): void {
    $removed = domain_runtime_definition_instance($this->orbitApp, $this->gateway, 'removed');
    $unrelated = domain_runtime_definition_instance($this->orbitApp, $this->gateway, 'unrelated');
    $processDefinition = $this->orbitApp->processDefinitions()->create([
        'name' => 'worker',
        'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php']],
    ]);
    $scheduleDefinition = $this->orbitApp->scheduleDefinitions()->create([
        'name' => 'report',
        'environments' => ['production'],
        'spec' => ['command' => 'php artisan report', 'calendar' => 'daily', 'timeout_seconds' => 60],
    ]);
    $unrelatedBefore = $unrelated->fresh()->getAttributes();

    $removed->delete();

    expect($processDefinition->fresh())
        ->not->toBeNull()
        ->and($scheduleDefinition->fresh())
        ->not->toBeNull()
        ->and($unrelated->fresh()->getAttributes())
        ->toBe($unrelatedBefore);
});

it('cascades both definition kinds when an otherwise removable App is deleted', function (): void {
    $processDefinition = $this->orbitApp->processDefinitions()->create([
        'name' => 'worker',
        'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php']],
    ]);
    $scheduleDefinition = $this->orbitApp->scheduleDefinitions()->create([
        'name' => 'report',
        'environments' => ['production'],
        'spec' => ['command' => 'php artisan report', 'calendar' => 'daily', 'timeout_seconds' => 60],
    ]);

    app(RemoveAppAction::class)->execute($this->orbitApp);

    expect($processDefinition->fresh())
        ->toBeNull()
        ->and($scheduleDefinition->fresh())
        ->toBeNull();
});

function domain_runtime_definition_app(string $slug): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'repository_url' => "https://example.test/{$slug}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
}

function domain_runtime_definition_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'checkout_path' => "/srv/{$name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => 'active',
    ]);
}

/** @return array{name: string, environments: list<string>, spec: array<string, mixed>} */
function domain_process_definition_payload(
    array $environments = ['development'],
    array $spec = ['runtime' => 'systemd', 'command' => ['/usr/bin/php']],
): array {
    return ['name' => 'worker', 'environments' => $environments, 'spec' => $spec];
}

/** @return array{name: string, environments: list<string>, spec: array<string, mixed>} */
function domain_schedule_definition_payload(
    array $environments = ['development'],
    array $spec = ['command' => 'php artisan report', 'calendar' => 'daily', 'timeout_seconds' => 60],
): array {
    return ['name' => 'report', 'environments' => $environments, 'spec' => $spec];
}
