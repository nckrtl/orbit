<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDeployment;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceDeploymentsRequest;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentRequest;

/**
 * Records the deployment history responses that the CLI replays for
 * `instance:deployment:list` and `instance:deployment:show`. The recorded
 * deployment is seeded directly so its fields, events, and log stay fixed
 * example values instead of the live stream's binary-safety test data.
 */
it('records the deployment list and show responses', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));

    $caller = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]);
    $this->markAsGateway($caller);
    $app = OrbitApp::query()->create([
        'name' => 'Charlie Shop',
        'slug' => 'charlie-shop',
        'repository_url' => 'https://example.test/charlie-shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $caller->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/charlie-shop/releases/20260101000000',
        'production_user' => 'charlie-shop',
        'production_home' => '/home/charlie-shop',
        'root' => 'public',
        'branch' => 'main',
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    $deployment = AppInstanceDeployment::query()->create([
        'app_instance_id' => $instance->id,
        'release' => '20260101000000',
        'branch' => 'main',
        'commit' => str_repeat('a', 40),
        'started_at' => Carbon::parse('2026-01-01T00:00:00Z'),
        'finished_at' => Carbon::parse('2026-01-01T00:00:42Z'),
        'duration_seconds' => 42,
        'status' => 'succeeded',
        'failed_step' => null,
        'error_code' => null,
        'selected_release' => '20260101000000',
        'triggered_by' => 'gateway',
        'events' => [
            ['type' => 'phase', 'phase' => 'source_preparation', 'step_name' => null],
            ['type' => 'phase', 'phase' => 'before_activation', 'step_name' => 'prepare'],
            ['type' => 'output', 'step' => 'prepare', 'stream' => 'stdout', 'value_base64' => base64_encode("Running composer install\n")],
            ['type' => 'phase', 'phase' => 'activation', 'step_name' => null],
        ],
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', fixture_request_id());

    record_fixture(
        $this->getJson("/api/v1/instances/{$instance->id}/deployments")->assertOk(),
        'instances/instance-deployment-list/default',
        ListAppInstanceDeploymentsRequest::class,
        'GET /api/v1/instances/{instance}/deployments',
    );

    record_fixture(
        $this->getJson("/api/v1/deployments/{$deployment->id}")->assertOk(),
        'instances/instance-deployment-show/default',
        ShowAppInstanceDeploymentRequest::class,
        'GET /api/v1/deployments/{deployment}',
    );
});
