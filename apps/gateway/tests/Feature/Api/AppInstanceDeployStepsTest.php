<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;

beforeEach(function (): void {
    [$this->caller, $this->owner, $this->orbitApp, $this->instance] = deployment_api_fixture();
    $this->url = "/api/v1/instances/{$this->instance->id}/deploy-steps";
});

it('creates a before-activation step at the end of its phase and honors placement', function (): void {
    $first = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, [
            'name' => 'migrate',
            'command' => 'php artisan migrate --force',
        ]);

    $first
        ->assertCreated()
        ->assertJsonPath('data.name', 'migrate')
        ->assertJsonPath('data.phase', 'before_activation')
        ->assertJsonPath('data.timeout_seconds', 300);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, [
            'name' => 'optimize',
            'command' => 'php artisan optimize',
            'phase' => 'after_activation',
            'timeout_seconds' => 45,
        ])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, [
            'name' => 'cache',
            'command' => 'php artisan config:cache',
            'before' => 'migrate',
        ])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, [
            'name' => 'warmup',
            'command' => 'php artisan cache:warm',
            'after' => 'migrate',
        ])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'cache')
        ->assertJsonPath('data.1.name', 'migrate')
        ->assertJsonPath('data.2.name', 'warmup')
        ->assertJsonPath('data.3.name', 'optimize');
});

it('refuses duplicate names unknown placement a thirty-third step and timeout limits without change', function (
    array $existing,
    array $payload,
): void {
    store_deploy_steps($this->instance, $existing);
    $before = normalized_deploy_steps($this->instance);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(normalized_deploy_steps($this->instance->fresh()))->toBe($before);
})->with([
    'duplicate name' => [
        [['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'migrate', 'timeout_seconds' => 30]],
        ['name' => 'migrate', 'command' => 'other'],
    ],
    'unknown placement' => [
        [['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'migrate', 'timeout_seconds' => 30]],
        ['name' => 'cache', 'command' => 'cache', 'before' => 'missing'],
    ],
    'timeout over 900' => [
        [],
        ['name' => 'slow', 'command' => 'sleep 1', 'timeout_seconds' => 901],
    ],
]);

it('refuses a thirty-third step and a timeout total over 3600 seconds without change', function (
    int $count,
    int $timeout,
): void {
    $existing = [];

    for ($index = 1; $index <= $count; $index++) {
        $existing[] = [
            'name' => 'step-'.$index,
            'phase' => 'before_activation',
            'command' => 'true',
            'timeout_seconds' => $timeout,
        ];
    }

    store_deploy_steps($this->instance, $existing);
    $before = normalized_deploy_steps($this->instance);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => $timeout])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(normalized_deploy_steps($this->instance->fresh()))->toBe($before);
})->with([
    'thirty-third step' => [32, 30],
    'timeout total' => [4, 900],
]);

it('updates and destroys a step by name and includes steps on instance show', function (): void {
    store_deploy_steps($this->instance, [
        ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'old', 'timeout_seconds' => 30],
        ['name' => 'optimize', 'phase' => 'after_activation', 'command' => 'optimize', 'timeout_seconds' => 30],
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson($this->url.'/migrate', [
            'command' => 'php artisan migrate --force',
            'timeout_seconds' => 60,
            'after' => 'optimize',
            'phase' => 'after_activation',
        ])
        ->assertOk()
        ->assertJsonPath('data.command', 'php artisan migrate --force')
        ->assertJsonPath('data.phase', 'after_activation')
        ->assertJsonPath('data.timeout_seconds', 60);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->instance->id}")
        ->assertOk()
        ->assertJsonPath('data.deploy_steps.0.name', 'optimize')
        ->assertJsonPath('data.deploy_steps.1.name', 'migrate');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->deleteJson($this->url.'/optimize')
        ->assertOk()
        ->assertJsonPath('data.name', 'optimize');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'migrate')
        ->assertJsonCount(1, 'data');
});

it('changes the deployment branch without changing steps and refuses development AppInstances', function (): void {
    store_deploy_steps($this->instance, [
        ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'migrate', 'timeout_seconds' => 30],
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$this->instance->id}", ['branch' => 'release/next'])
        ->assertOk()
        ->assertJsonPath('data.deploy_steps.0.name', 'migrate');

    expect($this->instance->fresh()->deployment_branch)->toBe('release/next')
        ->and(normalized_deploy_steps($this->instance->fresh())[0]['name'])->toBe('migrate');

    $this->instance->update(['environment' => 'development']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$this->instance->id}", ['branch' => 'other'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'deployment_config.unavailable');
});

it('refuses a step mutation while the AppInstance operation owner is held', function (): void {
    app()->instance(AppInstanceEnvironmentOperationLock::class, new class implements AppInstanceEnvironmentOperationLock
    {
        public function run(array $appInstanceIds, Closure $operation): mixed
        {
            throw new ResourceOperationException(
                errorCode: 'env.operation_busy',
                message: 'Another AppInstance environment operation is active. Retry the request.',
                status: 409,
            );
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, ['name' => 'migrate', 'command' => 'migrate'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.operation_busy');
});

it('makes document writes visible to list and step writes visible to the document', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/deployment-config", [
            'branch' => 'main',
            'steps' => [[
                'name' => 'migrate',
                'phase' => 'before_activation',
                'command' => 'php artisan migrate --force',
            ]],
        ])
        ->assertOk();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'migrate')
        ->assertJsonPath('data.0.timeout_seconds', 300);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, [
            'name' => 'optimize',
            'command' => 'php artisan optimize',
            'phase' => 'after_activation',
        ])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->instance->id}/deployment-config")
        ->assertOk()
        ->assertJsonPath('data.branch', 'main')
        ->assertJsonPath('data.steps.0.name', 'migrate')
        ->assertJsonPath('data.steps.1.name', 'optimize');
});

it('keeps commands out of Activity for deploy-step mutations', function (): void {
    $sentinel = 'secret-command-sentinel';

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->url, ['name' => 'secret-step', 'command' => $sentinel])
        ->assertCreated();

    $success = Activity::query()->latest('id')->firstOrFail();
    expect(json_encode($success->properties?->toArray()))->not->toContain($sentinel)
        ->and($success->properties?->get('input'))->toBe([]);
});
