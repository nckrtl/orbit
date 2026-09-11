<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    [$this->caller, $this->owner, $this->orbitApp, $this->instance] = deployment_api_fixture();
    $this->url = "/api/v1/instances/{$this->instance->id}/deployment-config";
});

it('returns legacy configuration and atomically replaces the complete normalized value', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.branch', 'main')
        ->assertJsonPath('data.steps', []);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($this->url, [
            'branch' => 'release/next',
            'steps' => [
                [
                    'name' => 'migrate',
                    'phase' => 'before_activation',
                    'command' => 'php artisan migrate --force',
                ],
                [
                    'name' => 'restart-workers',
                    'phase' => 'after_activation',
                    'command' => 'php artisan queue:restart',
                    'timeout_seconds' => 45,
                ],
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.branch', 'release/next')
        ->assertJsonPath('data.steps.0.timeout_seconds', 300)
        ->assertJsonPath('data.steps.1.timeout_seconds', 45);

    $stored = $this->instance->fresh();
    expect($stored)
        ->deployment_branch->toBe('release/next')
        ->branch->toBe('main')
        ->branch_override->toBe('main')
        ->and($stored->deployment_steps)->toBe($response->json('data.steps'))
        ->and($stored->toArray())->not->toHaveKey('deployment_steps');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.steps.0.name', 'migrate')
        ->assertJsonPath('data.steps.1.name', 'restart-workers');
});

it('rejects malformed duplicate unknown and wrongly typed input without mutation', function (string $body): void {
    $before = $this->instance->only(['deployment_branch', 'deployment_steps']);
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('PUT', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->getContent())->not->toContain('secret-command-sentinel')
        ->and($this->instance->fresh()->only(['deployment_branch', 'deployment_steps']))->toBe($before);
})->with([
    'malformed' => '{"branch":"main","steps":',
    'not object' => '[]',
    'missing branch' => '{"steps":[]}',
    'missing steps' => '{"branch":"main"}',
    'duplicate top level' => '{"branch":"main","br\u0061nch":"other","steps":[]}',
    'unknown top level' => '{"branch":"main","steps":[],"execute":true}',
    'wrong branch type' => '{"branch":false,"steps":[]}',
    'wrong steps type' => '{"branch":"main","steps":{}}',
    'non-object step' => '{"branch":"main","steps":["secret-command-sentinel"]}',
    'duplicate nested' => '{"branch":"main","steps":[{"name":"one","phase":"before_activation","command":"secret-command-sentinel","comm\u0061nd":"other"}]}',
    'unknown nested' => '{"branch":"main","steps":[{"name":"one","phase":"before_activation","command":"secret-command-sentinel","shell":"sh"}]}',
    'wrong timeout type' => '{"branch":"main","steps":[{"name":"one","phase":"before_activation","command":"secret-command-sentinel","timeout_seconds":"300"}]}',
    'unknown phase' => '{"branch":"main","steps":[{"name":"one","phase":"during_activation","command":"secret-command-sentinel"}]}',
    'duplicate names' => '{"branch":"main","steps":[{"name":"same","phase":"before_activation","command":"secret-command-sentinel"},{"name":"same","phase":"after_activation","command":"other"}]}',
    'invalid command' => '{"branch":"main","steps":[{"name":"one","phase":"before_activation","command":""}]}',
]);

it('enforces active-peer owning-Node authorization before reading or writing', function (): void {
    $denied = Node::query()->create([
        'name' => 'deployment-denied',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.143',
        'wireguard_ip' => '10.44.0.143',
    ]);

    $this->getJson($this->url)->assertForbidden();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->getJson($this->url)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->putJson($this->url, ['branch' => 'other', 'steps' => []])
        ->assertForbidden();

    expect($this->instance->fresh()->deployment_branch)->toBeNull();
});

it('returns a bounded conflict for development and incomplete production instances', function (
    string $environment,
    string $status,
    ?string $branch,
): void {
    $this->instance->update(compact('environment', 'status', 'branch'));

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson($this->url)
        ->assertConflict()
        ->assertJsonPath('error.code', 'deployment_config.unavailable');
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($this->url, ['branch' => 'other', 'steps' => []])
        ->assertConflict()
        ->assertJsonPath('error.code', 'deployment_config.unavailable');
})->with([
    'development' => ['development', 'source_resolved', 'main'],
    'reserved' => ['production', 'reserved', 'main'],
    'checkout prepared' => ['production', 'checkout_prepared', 'main'],
    'null source branch' => ['production', 'source_resolved', null],
]);

it('keeps commands out of Activity and generic diagnostics', function (): void {
    $sentinel = 'secret-command-sentinel';
    $valid = [
        'branch' => 'main',
        'steps' => [[
            'name' => 'secret-step',
            'phase' => 'before_activation',
            'command' => $sentinel,
        ]],
    ];

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($this->url, $valid)
        ->assertOk();

    $success = Activity::query()->latest('id')->firstOrFail();
    expect(json_encode($success->properties?->toArray()))->not->toContain($sentinel)
        ->and($success->properties?->get('input'))->toBe([]);

    $invalid = str_replace('"secret-step"', '"INVALID"', json_encode($valid, JSON_THROW_ON_ERROR));
    $validationResponse = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('PUT', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: $invalid);
    $validationResponse->assertUnprocessable();

    expect($validationResponse->getContent())->not->toContain($sentinel)
        ->and(json_encode(Activity::query()->latest('id')->firstOrFail()->properties?->toArray()))
        ->not->toContain($sentinel);

    Event::listen('eloquent.updating: '.AppInstance::class, static function () use ($sentinel): never {
        throw new RuntimeException($sentinel);
    });

    try {
        $valid['branch'] = 'release/failure';
        $failureResponse = $this
            ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->putJson($this->url, $valid);

        $failureResponse
            ->assertInternalServerError()
            ->assertJsonPath('error.code', 'gateway.unhandled');
        expect($failureResponse->getContent())->not->toContain($sentinel)
            ->and(json_encode(Activity::query()->latest('id')->firstOrFail()->properties?->toArray()))
            ->not->toContain($sentinel)
            ->and($this->instance->fresh()->deployment_branch)->toBe('main')
            ->and($this->instance->fresh()->deployment_steps)->toBe([
                [
                    'name' => 'secret-step',
                    'phase' => 'before_activation',
                    'command' => $sentinel,
                    'timeout_seconds' => 300,
                ],
            ]);
    } finally {
        Event::forget('eloquent.updating: '.AppInstance::class);
    }
});

/** @return array{Node, Node, OrbitApp, AppInstance} */
function deployment_api_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'deployment-gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.140',
        'wireguard_ip' => '10.44.0.140',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $owner = Node::query()->create([
        'name' => 'deployment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.141',
        'wireguard_ip' => '10.44.0.141',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Deployment API',
        'slug' => 'deployment-api',
        'repository_url' => 'https://example.test/deployment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $owner->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/home/deployment-api/releases/initial',
        'production_user' => 'deployment-api',
        'production_home' => '/home/deployment-api',
        'branch' => 'main',
        'branch_override' => 'main',
        'status' => 'source_resolved',
    ]);

    return [$caller, $owner, $app, $instance->fresh()];
}
