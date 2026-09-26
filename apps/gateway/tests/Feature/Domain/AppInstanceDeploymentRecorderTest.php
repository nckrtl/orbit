<?php

declare(strict_types=1);

use App\Domain\AppInstances\Deployment\AppInstanceDeploymentRecorder;
use App\Domain\AppInstances\Deployment\DeploymentFailureBoundary;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentResult;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDeployment;
use App\Models\Node;
use Illuminate\Support\Facades\Event;

function orb350_deployment_recorder_instance(): AppInstance
{
    $node = Node::query()->create([
        'name' => 'deployment-recorder-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.223',
        'wireguard_ip' => '10.44.0.223',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Deployment Recorder',
        'slug' => 'deployment-recorder',
        'repository_url' => 'https://example.test/deployment-recorder.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'root' => 'public',
        'branch' => 'main',
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

it('records a running row at start and fills the outcome at finish', function (): void {
    $instance = orb350_deployment_recorder_instance();
    $instance->update(['deployment_branch' => 'release-branch']);
    $recorder = new AppInstanceDeploymentRecorder;

    $deployment = $recorder->start($instance, 'caller-node');

    expect($deployment->status)->toBe('running')
        ->and($deployment->branch)->toBe('release-branch')
        ->and($deployment->triggered_by)->toBe('caller-node')
        ->and($deployment->finished_at)->toBeNull();

    $release = new DeploymentRelease('20260918120000', '/home/orbit-app-1/releases/20260918120000', 'abc1234');
    $recorder->finish($deployment, DeploymentResult::succeeded($release), [
        ['type' => 'phase', 'phase' => 'source_preparation', 'step_name' => null],
    ]);

    $deployment = $deployment->fresh();

    expect($deployment->status)->toBe('succeeded')
        ->and($deployment->release)->toBe('20260918120000')
        ->and($deployment->commit)->toBe('abc1234')
        ->and($deployment->selected_release)->toBe('20260918120000')
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->duration_seconds)->toBeGreaterThanOrEqual(0)
        ->and($deployment->events)->toBe([
            ['type' => 'phase', 'phase' => 'source_preparation', 'step_name' => null],
        ]);
});

it('records a failed run with its boundary and error code', function (): void {
    $instance = orb350_deployment_recorder_instance();
    $recorder = new AppInstanceDeploymentRecorder;
    $deployment = $recorder->start($instance, 'caller-node');

    $recorder->finish($deployment, DeploymentResult::failed(
        null,
        null,
        DeploymentFailureBoundary::Activation,
        'deployment.command_timed_out',
    ), []);

    $deployment = $deployment->fresh();

    expect($deployment->status)->toBe('failed')
        ->and($deployment->failed_step)->toBe('activation')
        ->and($deployment->error_code)->toBe('deployment.command_timed_out')
        ->and($deployment->release)->toBeNull();
});

it('retains only the most recent 50 deployments per AppInstance', function (): void {
    $instance = orb350_deployment_recorder_instance();
    $recorder = new AppInstanceDeploymentRecorder;

    for ($i = 0; $i < 55; $i++) {
        $deployment = $recorder->start($instance, 'caller-node');
        $recorder->finish($deployment, DeploymentResult::succeeded(
            new DeploymentRelease((string) (20260918120000 + $i), '/home/orbit-app-1/releases/'.$i, 'commit'.$i),
        ), []);
    }

    expect(AppInstanceDeployment::query()->where('app_instance_id', $instance->id)->count())
        ->toBe(AppInstanceDeploymentRecorder::RETAINED_PER_INSTANCE)
        ->and(AppInstanceDeployment::query()->where('app_instance_id', $instance->id)->orderBy('id')->value('release'))
        ->toBe('20260918120005');
});

it('broadcasts deployment.created at start and deployment.updated with the outcome at finish', function (): void {
    Event::fake([RecordBroadcast::class]);
    $instance = orb350_deployment_recorder_instance();
    $recorder = new AppInstanceDeploymentRecorder;

    $deployment = $recorder->start($instance, 'caller-node');

    Event::assertDispatched(
        RecordBroadcast::class,
        fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DeploymentCreated
            && $event->id === $deployment->id
            && $event->data['app_instance_id'] === $instance->id
            && $event->data['status'] === 'running',
    );

    $recorder->finish($deployment, DeploymentResult::failed(
        null,
        null,
        DeploymentFailureBoundary::Activation,
        'deployment.command_timed_out',
    ), [['type' => 'phase', 'phase' => 'activation', 'step_name' => null]]);

    Event::assertDispatched(
        RecordBroadcast::class,
        fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DeploymentUpdated
            && $event->id === $deployment->id
            && $event->data['status'] === 'failed'
            && $event->data['error_code'] === 'deployment.command_timed_out'
            && ! array_key_exists('events', $event->data),
    );
    Event::assertDispatchedTimes(RecordBroadcast::class, 2);
});
