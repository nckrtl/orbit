<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->deployUrl = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
    $this->rollbackUrl = "/api/v1/instances/{$this->fixture->instance->id}/rollback";
});

it('rejects invalid deployment requests as ordinary JSON before invocation', function (
    string $endpoint,
    string $body,
): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $endpoint === 'deploy' ? $this->deployUrl : $this->rollbackUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $body);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertHeaderMissing('X-Accel-Buffering');

    expect($this->fixture->deployment->invocations)->toBe(0);
})->with([
    'deploy missing body' => ['deploy', ''],
    'deploy array' => ['deploy', '[]'],
    'deploy malformed' => ['deploy', '{'],
    'deploy unknown member' => ['deploy', '{"release":"initial"}'],
    'rollback missing release' => ['rollback', '{}'],
    'rollback duplicate release' => ['rollback', '{"release":"initial","release":"fresh"}'],
    'rollback unknown member' => ['rollback', '{"release":"initial","force":true}'],
    'rollback wrong type' => ['rollback', '{"release":false}'],
    'rollback unsafe name' => ['rollback', '{"release":"../outside"}'],
]);

it('returns JSON for missing models and denied Node access before opening a stream', function (): void {
    $denied = Node::query()->create([
        'name' => 'deployment-denied',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.222',
        'wireguard_ip' => '10.44.0.222',
        'user' => 'orbit',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', '/api/v1/instances/999999/deploy', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->call('POST', $this->deployUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{}')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertHeaderMissing('X-Accel-Buffering');

    expect($this->fixture->deployment->invocations)->toBe(0);
});

it('returns only retained names and the nullable current selection', function (): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->fixture->instance->id}/releases");

    $response->assertOk()->assertExactJson([
        'data' => [
            'releases' => ['fresh', 'initial'],
            'selected_release' => 'initial',
        ],
        'meta' => ['request_id' => $response->headers->get('X-Orbit-Request-Id')],
    ]);

    expect(Activity::query()->sole()->properties?->toArray())
        ->not->toHaveKeys(['history', 'output', 'commit']);
});
