<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\AppDev\ViteProcessLifecycle;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Infrastructure\Instances\NativeInstanceTransferRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

it('keeps node scoped assignments and retains both placements until transfer cleanup', function (): void {
    $node = Node::query()->create(['name' => 'vite-source', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    orbit_test_set_app_placement_role($node, false);
    $destination = Node::query()->create(['name' => 'vite-destination', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    orbit_test_set_app_placement_role($destination, false);
    $project = Project::query()->create(['name' => 'Vite', 'slug' => 'vite', 'repository_url' => 'git@example.test:vite.git']);
    $first = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/apps/vite/main']);
    $second = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'next', 'checkout_path' => '/apps/vite/next']);
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->withArgs(fn ($n, $preferred, $excluded) => $n->id === $node->id && $preferred === 5173 && ! in_array(5173, $excluded, true))->once()->andReturn(5173);
    $runtime->shouldReceive('selectPort')->withArgs(fn ($n, $preferred, $excluded) => $n->id === $node->id && in_array(5173, $excluded, true))->once()->andReturn(5174);
    $runtime->shouldReceive('selectPort')->withArgs(fn ($n, $preferred, $excluded) => $n->id === $destination->id && ! in_array(5173, $excluded, true))->once()->andReturn(5173);
    app()->instance(VitePortRuntime::class, $runtime);
    $allocator = app(VitePortAllocator::class);

    expect($allocator->assign($first))->toBe(5173);
    expect($allocator->assign($first->refresh()))->toBe(5173);
    expect($allocator->assign($second))->toBe(5174);
    expect($allocator->assign($first, $destination))->toBe(5173);
    expect(DB::table('vite_port_assignments')->where('instance_id', $first->id)->count())->toBe(2);
    $allocator->release($first, $node);
    expect(DB::table('vite_port_assignments')->where('instance_id', $first->id)->pluck('node_id')->all())->toBe([$destination->id]);
});

it('rechecks a preferred port and persists its replacement without allocating for production', function (): void {
    $node = Node::query()->create(['name' => 'vite-source', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    orbit_test_set_app_placement_role($node, false);
    $productionNode = Node::query()->create(['name' => 'vite-production', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.11']);
    orbit_test_set_app_placement_role($productionNode, true);
    $project = Project::query()->create(['name' => 'Vite', 'slug' => 'vite', 'repository_url' => 'git@example.test:vite.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/apps/vite/main']);
    $production = Instance::query()->create(['project_id' => $project->id, 'node_id' => $productionNode->id, 'name' => 'prod', 'checkout_path' => '/var/www/vite']);
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->withArgs(fn ($n, $preferred, $excluded) => $preferred === 5173 && in_array(5432, $excluded, true) && in_array(6379, $excluded, true))->twice()->andReturn(5173, 5175);
    app()->instance(VitePortRuntime::class, $runtime);
    $allocator = app(VitePortAllocator::class);

    expect($allocator->assign($production))->toBeNull();
    expect($allocator->assign($instance))->toBe(5173);
    expect($allocator->assign($instance, recheck: true))->toBe(5175);
    expect($instance->refresh()->vite_port)->toBe(5175);
    expect(DB::table('vite_port_assignments')->count())->toBe(1);
});

it('keeps a reserved assignment when projection fails and retries from that assignment', function (): void {
    $node = Node::query()->create(['name' => 'retry', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.12']);
    orbit_test_set_app_placement_role($node, false);
    $project = Project::query()->create(['name' => 'Retry', 'slug' => 'retry', 'repository_url' => 'git@example.test:retry.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/apps/retry/main']);
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->twice()->andReturn(5173);
    $runtime->shouldReceive('ownsListener')->once()->andReturn(false);
    $runtime->shouldReceive('suspendTraffic')->once();
    $runtime->shouldReceive('prepare')->once();
    $runtime->shouldReceive('project')->once()->andThrow(new RuntimeConvergenceException('vite-proxy', 'vite.proxy_failed', 'Projection failed.'));
    app()->instance(VitePortRuntime::class, $runtime);
    expect(fn () => app(ViteProcessLifecycle::class)->run(new Process(['owner_id' => $instance->id]), function (): void {
        throw new RuntimeException('Must not launch');
    }, function (): void {}, true))->toThrow(ProcessOperationException::class, 'Projection failed.');
    expect(app(VitePortAllocator::class)->assign($instance->refresh()))->toBe(5173);
});

it('relocates the preset environment with its working directory', function (string $webRoot, string $suffix): void {
    $node = Node::query()->create(['name' => 'relocate', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    orbit_test_set_app_placement_role($node, false);
    $project = Project::query()->create(['name' => 'Relocate', 'slug' => 'relocate', 'repository_url' => 'git@example.test:relocate.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/apps/old', 'root' => $webRoot, 'source_is_laravel' => true]);
    $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'name' => 'assets', 'runtime' => 'systemd', 'runtime_config' => ['preset' => 'vp-dev', 'command' => VpDevPreset::command(), 'environment_file' => '/apps/old/.env'], 'working_directory' => '/apps/old', 'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active']);
    app(NativeInstanceTransferRuntime::class)->relocate($instance, $node, '/apps/old', '/apps/new');
    expect($process->refresh()->working_directory)->toBe('/apps/new')->and($process->runtime_config['environment_file'])->toBe('/apps/new'.$suffix.'/.env')->and($process->desired_state->value)->toBe('running');
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']]);

it('does not remove the destination service during cleanup when both nodes use the same checkout path', function (): void {
    $source = Node::query()->create(['name' => 'source-cleanup', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    orbit_test_set_app_placement_role($source, false);
    $destination = Node::query()->create(['name' => 'destination-cleanup', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.11']);
    orbit_test_set_app_placement_role($destination, false);
    $project = Project::query()->create(['name' => 'Same path', 'slug' => 'same-path', 'repository_url' => 'git@example.test:same-path.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $destination->id, 'name' => 'main', 'checkout_path' => '/apps/main']);
    $instance->processes()->create(['name' => 'assets', 'runtime' => 'systemd', 'working_directory' => '/apps/main', 'runtime_config' => ['command' => ['/bin/true']], 'desired_state' => 'running', 'status' => 'active']);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldNotReceive('remove');
    $schedules = Mockery::mock(ScheduleRuntimeManager::class);
    new NativeInstanceTransferRuntime($runtime, $schedules)->cleanupSourceArtifacts($instance, $source, '/apps/main');
});
