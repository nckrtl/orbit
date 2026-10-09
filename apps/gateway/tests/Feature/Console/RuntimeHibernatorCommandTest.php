<?php

declare(strict_types=1);

use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Tests\Support\FakeInstanceCheckoutInspector;
use Tests\Support\FakeInstanceRuntimeReadiness;
use Tests\Support\ProcessesApiFakeRuntimeManager;

it('reports how many idle Instance groups the hibernator halted', function (): void {
    $this->freezeTime();
    $runtime = new ProcessesApiFakeRuntimeManager;
    $markers = new class implements HibernationMarkerStore
    {
        public function markAwake(Node $node, string $key): void {}

        public function markAsleep(Node $node, string $key): void {}

        public function markCold(Node $node, string $key): void {}

        public function clearCold(Node $node, string $key): void {}

        public function lastActivityUnix(Node $node, string $key): ?int
        {
            return Carbon::now()->subSeconds(3_601)->getTimestamp();
        }

        public function isAwake(Node $node, string $key): bool
        {
            return false;
        }

        public function isCold(Node $node, string $key): bool
        {
            return false;
        }
    };
    app()->instance(ProcessRuntimeManager::class, $runtime);
    app()->instance(HibernationMarkerStore::class, $markers);
    app()->instance(InstanceRuntimeReadiness::class, new FakeInstanceRuntimeReadiness);
    app()->instance(InstanceCheckoutInspector::class, new FakeInstanceCheckoutInspector);

    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
        'apps' => fixture_apps(null),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => 'vite',
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => 'active',
    ]);

    $this->artisan('orbit:runtime-hibernator')
        ->expectsOutput('Halted [1] idle app-dev Instance runtime groups.')
        ->expectsOutput('Pruned [0] cold app-dev Instance dependency trees.')
        ->assertSuccessful();

    expect($runtime->stopped)->toHaveCount(1);
});
