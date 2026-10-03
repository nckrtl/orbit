<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Infrastructure\Processes\CommandResult;
use App\Models\InstanceDeployment;
use Tests\Support\Orb220DeploymentApiFixture;

it('deploys an app-dev default through the existing API and preserves best-effort warnings in history and the stream', function (): void {
    $fixture = Orb220DeploymentApiFixture::create();
    $fixture->owner->roles()->update(['role' => 'app-dev']);
    $home = '/fast/apps/deployment-stream/default';
    $fixture->instance->update(['name' => 'default', 'source_layout' => 'checkout', 'checkout_path' => $home]);
    app(ProjectDevelopmentDeployStepStore::class)->create($fixture->instance->project, new DevelopmentDeployStep('warm', 'false', required: false), null, null);
    $previous = new DeploymentRelease('initial', $home.'/releases/initial', str_repeat('a', 40));
    $release = new DeploymentRelease('fresh', $home.'/releases/fresh', str_repeat('b', 40));
    $remote = Mockery::mock(DevelopmentDeployment::class);
    $remote->shouldReceive('initialize')->once();
    $remote->shouldReceive('selected')->once()->andReturn($previous);
    $remote->shouldReceive('target')->once()->andReturn($release->commit);
    $remote->shouldReceive('prune')->twice();
    $remote->shouldReceive('prepare')->once()->andReturn($release);
    $remote->shouldReceive('executeStep')->once()->andThrow(new RuntimeConvergenceException('deployment-step-warm', 'deployment.step_failed', 'Failed warm-up.', result: new CommandResult(42, '', '', 1, false)));
    $remote->shouldReceive('activate')->once()->andReturn($release);
    $remote->shouldReceive('releases')->once()->andReturn(new DeploymentReleaseState(['fresh', 'initial'], 'fresh'));
    app()->instance(DevelopmentDeployment::class, $remote);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$fixture->instance->id}/deploy", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
    $response->assertOk();
    $response->streamedContent();
    $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter($fixture->connection->lines, static fn (string $line): bool => trim($line) !== '')));
    $final = end($events);
    $output = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'output'));

    expect($final['status'])->toBe('succeeded')
        ->and($final['selected_release'])->toBe('fresh')
        ->and(base64_decode($output[0]['data_base64']))->toContain('Best-effort step failed (exit 42): warm')
        ->and(InstanceDeployment::query()->sole()->status)->toBe('succeeded')
        ->and(InstanceDeployment::query()->sole()->selected_release)->toBe('fresh')
        ->and($fixture->deployment->invocations)->toBe(0);
    $this->getJson("/api/v1/instances/{$fixture->instance->id}/releases")->assertOk()->assertJsonPath('data.selected_release', 'fresh');
    $history = $this->getJson("/api/v1/instances/{$fixture->instance->id}/deployments");
    $history->assertOk()->assertJsonPath('data.0.status', 'succeeded');
});
