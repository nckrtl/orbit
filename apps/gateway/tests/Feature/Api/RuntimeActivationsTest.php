<?php

declare(strict_types=1);

use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeAppInstanceCheckoutInspector;
use Tests\Support\FakeAppInstanceRuntimeReadiness;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    $this->markers = new class implements HibernationMarkerStore
    {
        /** @var list<string> */
        public array $awake = [];

        public function markAwake(Node $node, string $key): void
        {
            $this->awake[] = $key;
        }

        public function markAsleep(Node $node, string $key): void {}

        public function markCold(Node $node, string $key): void {}

        public function clearCold(Node $node, string $key): void {}

        public function lastActivityUnix(Node $node, string $key): ?int
        {
            return null;
        }

        public function isAwake(Node $node, string $key): bool
        {
            return in_array($key, $this->awake, true);
        }

        public function isCold(Node $node, string $key): bool
        {
            return false;
        }
    };
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    app()->instance(HibernationMarkerStore::class, $this->markers);
    app()->instance(AppInstanceRuntimeReadiness::class, new FakeAppInstanceRuntimeReadiness);
    app()->instance(AppInstanceCheckoutInspector::class, new FakeAppInstanceCheckoutInspector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('returns the Orbit progress page before it starts Processes', function (): void {
    $running = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'vite',
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/vp', 'run', 'dev']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => 'active',
    ]);

    $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertStatus(401)
        ->assertSee('Starting development runtime', false)
        ->assertSee('http-equiv="refresh" content="2"', false);

    expect($this->runtime->started)
        ->toBe([$running->id])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('refuses wake from a different Node', function (): void {
    $stranger = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])
        ->getJson('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertForbidden();

    expect($this->runtime->started)->toBe([]);
});

it('returns an HTML failure page for an ineligible production AppInstance', function (): void {
    $this->instance->update(['environment' => 'production', 'production_user' => 'orbit-docs', 'production_home' => '/var/www/docs']);

    $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertStatus(503)
        ->assertSee('Development runtime failed', false)
        ->assertSee('http-equiv="refresh"', false);
});

it('returns an HTML progress page when another wake holds the AppInstance lock', function (): void {
    $this->mock(ProcessAdmissionLock::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andThrow(new ResourceOperationException(
            errorCode: 'process.operation_busy',
            message: 'Another Process operation is active for this AppInstance. Retry the request.',
            status: 409,
        ));
    });

    $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertStatus(401)
        ->assertSee('Starting development runtime', false)
        ->assertSee('http-equiv="refresh" content="2"', false);
});

it('returns an HTML failure page on the next intercept when Process start fails', function (): void {
    Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'vite',
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/vp', 'run', 'dev']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => 'active',
    ]);
    $this->runtime->failStartDuringCall = true;

    $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertStatus(401)
        ->assertSee('Starting development runtime', false);

    $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertStatus(503)
        ->assertSee('Development runtime failed', false)
        ->assertSee('The process did not start.', false)
        ->assertSee('http-equiv="refresh" content="5"', false);
});
