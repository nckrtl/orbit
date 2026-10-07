<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Infrastructure\Compute\TaskSandboxLifecycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\FakeSandboxModelProxy;

use function Pest\Laravel\mock;

function lifecycle_sandbox(): TaskSandbox
{
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Lifecycle', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => 'vm']);

    return TaskSandbox::query()->create(['id' => (string) Str::uuid(), 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'proof', 'state' => 'reserved', 'desired_power' => 'running', 'spec' => []]);
}

it('registers credentials before provisioning and keeps them across park and resume', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $sandbox = lifecycle_sandbox();
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('provision')->once()->andReturnUsing(function (TaskSandbox $row) use ($proxy): TaskSandbox {
        expect($row->model_key_registered_at)->not->toBeNull()->and($row->pi_token)->not->toBeNull();
        expect($proxy->keys)->toContain($row->model_key);
        $row->update(['state' => SandboxState::Running]);

        return $row;
    });
    $driver->shouldReceive('park')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);

        return $row;
    });
    $driver->shouldReceive('resume')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Running, 'desired_power' => 'running']);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->activate($sandbox, $driver);
    $keys = [$sandbox->model_key, $sandbox->pi_token];
    $lifecycle->park($sandbox, $driver);
    $lifecycle->activate($sandbox, $driver);

    expect([$sandbox->model_key, $sandbox->pi_token])->toBe($keys);
});

it('retains failed registration for retry and never provisions compute without confirmed credentials', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $proxy->available = false;
    $sandbox = lifecycle_sandbox();
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('provision');

    expect(fn () => app(TaskSandboxLifecycle::class)->activate($sandbox, $driver))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->model_key)->not->toBeNull()->and($sandbox->fresh()->state)->toBe(SandboxState::Reserved);
});

it('revokes before destruction and clears Pi credentials only after confirmed resource removal', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $sandbox = lifecycle_sandbox();
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('provision')->once()->andReturn($sandbox);
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->activate($sandbox, $driver);
    $key = $sandbox->model_key;
    $pi = $sandbox->pi_token;
    $proxy->available = false;
    expect(fn () => $lifecycle->destroy($sandbox, $driver))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->desired_power)->toBe('destroyed')->and($sandbox->fresh()->model_key)->toBe($key);
    expect(fn () => $lifecycle->activate($sandbox, $driver))->toThrow(ComputeException::class, 'cannot register');
    $proxy->available = true;
    config(['compute.model_proxy.enabled' => false]);
    $attempts = 0;
    $driver->shouldReceive('destroy')->times(3)->andReturnUsing(function (TaskSandbox $row) use (&$attempts, $proxy, $key, $pi): TaskSandbox {
        expect($row->model_key)->toBeNull()->and($proxy->keys)->not->toContain($key);
        if ($attempts++ === 0) {
            expect($row->pi_token)->toBe($pi);
            throw new ComputeException('compute.host_operation_failed', 'Retry removal');
        }
        $row->update(['state' => SandboxState::Destroyed]);

        return $row;
    });
    expect(fn () => $lifecycle->destroy($sandbox, $driver))->toThrow(ComputeException::class, 'Retry removal');
    expect($sandbox->fresh()->pi_token)->toBe($pi);
    $lifecycle->destroy($sandbox, $driver);
    $lifecycle->destroy($sandbox, $driver);

    expect($sandbox->fresh()->pi_token)->toBeNull()->and($sandbox->fresh()->model_key)->toBeNull();
});

it('does not overlap activation and destruction for a reservation', function (): void {
    $sandbox = lifecycle_sandbox();
    $lock = Cache::lock('orbit:compute:sandbox:'.$sandbox->id, 2400);
    $lock->get();
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('provision', 'destroy');
    try {
        expect(fn () => app(TaskSandboxLifecycle::class)->activate($sandbox, $driver))->toThrow(ComputeException::class, 'lifecycle operation');
        expect(fn () => app(TaskSandboxLifecycle::class)->destroy($sandbox, $driver))->toThrow(ComputeException::class, 'lifecycle operation');
    } finally {
        $lock->release();
    }
    expect($sandbox->fresh()->model_key)->toBeNull()->and($sandbox->fresh()->desired_power)->toBe('running');
});
