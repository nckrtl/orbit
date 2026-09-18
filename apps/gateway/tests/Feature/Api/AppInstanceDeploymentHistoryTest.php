<?php

declare(strict_types=1);

use App\Models\AppInstanceDeployment;
use Illuminate\Support\Str;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->deployUrl = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
    $this->rollbackUrl = "/api/v1/instances/{$this->fixture->instance->id}/rollback";
});

it('records a deployment row on deploy and lists it newest first with its phases and log', function (): void {
    $requestId = (string) Str::uuid();
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->call('POST', $this->deployUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/x-ndjson',
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
        ], content: '{}');
    $response->assertOk();
    $response->streamedContent();

    $deployment = AppInstanceDeployment::query()->sole();

    expect($deployment->app_instance_id)->toBe($this->fixture->instance->id)
        ->and($deployment->status)->toBe('succeeded')
        ->and($deployment->branch)->toBe($this->fixture->instance->branch)
        ->and($deployment->triggered_by)->toBe($this->fixture->caller->name)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->duration_seconds)->toBeGreaterThanOrEqual(0)
        ->and($deployment->release)->not->toBeNull()
        ->and($deployment->events)->not->toBeEmpty();

    $list = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->fixture->instance->id}/deployments");

    $list->assertOk()
        ->assertJsonPath('data.0.id', $deployment->id)
        ->assertJsonPath('data.0.status', 'succeeded')
        ->assertJsonMissingPath('data.0.events');

    $show = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson("/api/v1/deployments/{$deployment->id}");

    $show->assertOk()
        ->assertJsonPath('data.id', $deployment->id)
        ->assertJsonPath('data.status', 'succeeded');

    $events = $show->json('data.events');

    expect($events)->not->toBeEmpty()
        ->and(array_column($events, 'type'))->toContain('phase');
});

it('records a failed deployment with its failed step and error code', function (): void {
    $this->fixture->deployment->failPreparation = true;

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->deployUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/x-ndjson',
        ], content: '{}');
    $response->assertOk();
    $response->streamedContent();

    $deployment = AppInstanceDeployment::query()->sole();

    expect($deployment->status)->toBe('failed')
        ->and($deployment->failed_step)->not->toBeNull()
        ->and($deployment->error_code)->not->toBeNull();
});

it('records a rollback as its own deployment row', function (): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->rollbackUrl, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/x-ndjson',
        ], content: '{"release":"initial"}');
    $response->assertOk();
    $response->streamedContent();

    $deployment = AppInstanceDeployment::query()->sole();

    expect($deployment->status)->toBe('succeeded')
        ->and($deployment->triggered_by)->toBe($this->fixture->caller->name);
});

it('answers an unknown deployment id and an instance with no history', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson('/api/v1/deployments/999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->fixture->instance->id}/deployments")
        ->assertOk()
        ->assertJsonPath('data', []);
});
