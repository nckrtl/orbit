<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\AppDev\ViteProcessLifecycle;
use App\Domain\Processes\ProcessOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeVitePortRuntime;

function vite_lifecycle_instance(): AppInstance
{
    $node = Node::query()->create(['name' => 'vite', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    $app = OrbitApp::query()->create(['name' => 'Vite', 'slug' => 'vite', 'repository_url' => 'git@example.test:vite.git']);

    return AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/apps/vite/main']);
}

it('start leaves site awake after Vite becomes ready', function (): void {
    $instance = vite_lifecycle_instance();
    $runtime = new ViteLifecycleAwakeRecordingRuntime;
    app()->instance(VitePortRuntime::class, $runtime);

    app(ViteProcessLifecycle::class)->run(new Process(['owner_id' => $instance->id]), function (): void {}, function (): void {}, true, explicitStart: true);

    expect($runtime->awakeInstances)->toBe([$instance->id]);
});

it('leaves site asleep after a preset restart becomes ready', function (): void {
    $instance = vite_lifecycle_instance();
    $runtime = new FakeVitePortRuntime;
    app()->instance(VitePortRuntime::class, $runtime);

    app(ViteProcessLifecycle::class)->run(new Process(['owner_id' => $instance->id]), function (): void {}, function (): void {}, true, restart: true);

    expect($runtime->awakeInstances)->toBe([]);
});

it('automatic start keeps healthy vite and preserves a healthy owned endpoint', function (): void {
    $instance = vite_lifecycle_instance();
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->once()->andReturn(5173);
    $runtime->shouldReceive('ownsListener')->once()->andReturn(true);
    $runtime->shouldReceive('ready')->once()->andReturn(true);
    $runtime->shouldNotReceive('suspendTraffic');
    $runtime->shouldNotReceive('prepare');
    $runtime->shouldNotReceive('project');
    $runtime->shouldNotReceive('markAwake');
    app()->instance(VitePortRuntime::class, $runtime);
    $launched = false;
    app(ViteProcessLifecycle::class)->run(new Process(['owner_id' => $instance->id]), function () use (&$launched): void {
        $launched = true;
    }, function (): void {
        throw new RuntimeException('Unexpected stop');
    }, true);
    expect($launched)->toBeFalse()->and($instance->refresh()->vite_port)->toBe(5173);
});

it('retries a confirmed bind conflict and projects the replacement before launch', function (): void {
    $instance = vite_lifecycle_instance();
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->times(4)->andReturn(5173, 5173, 5174, 5174);
    $runtime->shouldReceive('ownsListener')->twice()->andReturn(false);
    $runtime->shouldReceive('suspendTraffic')->once();
    $runtime->shouldReceive('prepare')->twice();
    $projected = [];
    $runtime->shouldReceive('project')->twice()->andReturnUsing(function (AppInstance $current) use (&$projected): void {
        $projected[] = $current->vite_port;
    });
    $runtime->shouldReceive('ready')->twice()->andReturn(false, true);
    $runtime->shouldReceive('markAwake')->once();
    app()->instance(VitePortRuntime::class, $runtime);
    $time = 0.0;
    $launched = [];
    $lifecycle = new ViteProcessLifecycle(app(AppDevSourceOperationLock::class), app(VitePortAllocator::class), $runtime, clock: function () use (&$time): float {
        return $time;
    }, wait: function () use (&$time): void {
        $time += 16;
    });
    $lifecycle->run(new Process(['owner_id' => $instance->id]), function () use (&$launched, &$projected, $instance): void {
        $port = $instance->refresh()->vite_port;
        expect(end($projected))->toBe($port);
        $launched[] = $port;
    }, function (): void {}, true, explicitStart: true);
    expect($launched)->toBe([5173, 5174]);
});

final class ViteLifecycleAwakeRecordingRuntime implements VitePortRuntime
{
    /** @var list<int> */
    public array $awakeInstances = [];

    public function selectPort(Node $node, int $preferred, array $excluded): int
    {
        return $preferred;
    }

    public function ownsListener(Process $process, AppInstance $instance, int $port): bool
    {
        return false;
    }

    public function ready(Process $process, AppInstance $instance, int $port): bool
    {
        return true;
    }

    public function suspendTraffic(AppInstance $instance): void {}

    public function markAwake(AppInstance $instance): void
    {
        $this->awakeInstances[] = $instance->id;
    }

    public function prepare(Process $process, AppInstance $instance): void {}

    public function project(AppInstance $instance): void {}
}

it('does not change ports or relaunch for a startup failure without a bind conflict', function (): void {
    $instance = vite_lifecycle_instance();
    $runtime = Mockery::mock(VitePortRuntime::class);
    $runtime->shouldReceive('selectPort')->times(3)->andReturn(5173);
    $runtime->shouldReceive('ownsListener')->twice()->andReturn(false);
    $runtime->shouldReceive('suspendTraffic')->once();
    $runtime->shouldReceive('prepare')->once();
    $runtime->shouldReceive('project')->once();
    $runtime->shouldReceive('ready')->once()->andReturn(false);
    app()->instance(VitePortRuntime::class, $runtime);
    $time = 0.0;
    $launches = 0;
    $lifecycle = new ViteProcessLifecycle(app(AppDevSourceOperationLock::class), app(VitePortAllocator::class), $runtime, clock: function () use (&$time): float {
        return $time;
    }, wait: function () use (&$time): void {
        $time += 16;
    });
    expect(function () use ($lifecycle, $instance, &$launches): void {
        $lifecycle->run(new Process(['owner_id' => $instance->id]), function () use (&$launches): void {
            $launches++;
        }, function (): void {}, true);
    })->toThrow(ProcessOperationException::class);
    expect($launches)->toBe(1)->and($instance->refresh()->vite_port)->toBe(5173);
});
