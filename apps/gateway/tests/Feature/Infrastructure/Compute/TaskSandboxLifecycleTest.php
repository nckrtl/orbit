<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxFleetRemover;
use App\Domain\Compute\SandboxState;
use App\Infrastructure\Compute\TaskSandboxLifecycle;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\FakeSandboxModelProxy;
use Tests\Support\UpCloudRuntimeWorkspace;

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

it('keeps one review deadline and parks at five minutes without repeating the snapshot', function (): void {
    $this->travelTo(now()->startOfSecond());
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Running]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('park')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    $started = now();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);
    $this->travel(299)->seconds();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);
    expect($sandbox->fresh()->state)->toBe(SandboxState::Running);
    $this->travel(1)->seconds();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);
    $parked = now();
    $this->travel(1)->hours();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Stopped)
        ->and($sandbox->fresh()->review_started_at->equalTo($started))->toBeTrue()
        ->and($sandbox->fresh()->parked_at->equalTo($parked))->toBeTrue();
});

it('ends the grace period as soon as another group waits for capacity', function (): void {
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Running]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('park')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);
    $this->travel(1)->seconds();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: true);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Stopped)->and($sandbox->fresh()->parked_at)->not->toBeNull();
});

it('retains a preview under pressure and permits explicit merge cleanup', function (): void {
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Running, 'provider' => 'upcloud']);
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('park', 'resume');
    $driver->shouldReceive('destroy')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Destroyed]);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->review($sandbox, $driver, preview: true, capacityWaiting: true);
    $this->travel(2)->hours();
    $lifecycle->review($sandbox, $driver, preview: true, capacityWaiting: true);
    expect($sandbox->fresh()->state)->toBe(SandboxState::Running)->and($sandbox->fresh()->preview)->toBeTrue();
    $lifecycle->destroy($sandbox, $driver);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Destroyed);
});

it('resumes a parked preview with credentials without extending its review deadline', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $this->travelTo(now()->startOfSecond());
    $started = now()->subMinutes(10);
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped', 'review_started_at' => $started, 'parked_at' => now()->subMinutes(5)]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('resume')->once()->andReturnUsing(function (TaskSandbox $row) use ($proxy): TaskSandbox {
        expect($row->pi_token)->not->toBeNull()->and($proxy->keys)->toContain($row->model_key);
        $row->update(['state' => SandboxState::Running, 'desired_power' => 'running']);

        return $row;
    });
    app(TaskSandboxLifecycle::class)->review($sandbox, $driver, preview: true, capacityWaiting: true);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Running)
        ->and($sandbox->fresh()->review_started_at->equalTo($started))->toBeTrue();
});

it('keeps UpCloud running for one hour and retains cleanup intent across retries', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $this->travelTo(now()->startOfSecond());
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['provider' => 'upcloud']);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('provision')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Running]);

        return $row;
    });
    $driver->shouldNotReceive('park');
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->activate($sandbox, $driver);
    $key = $sandbox->model_key;
    $started = now();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: true);
    $this->travel(3599)->seconds();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);
    expect($sandbox->fresh()->state)->toBe(SandboxState::Running);
    $this->travel(1)->seconds();
    $proxy->available = false;
    expect(fn () => $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->desired_power)->toBe('destroyed')->and($sandbox->fresh()->pi_token)->not->toBeNull();
    $proxy->available = true;
    $driver->shouldReceive('destroy')->once()->andReturnUsing(function (TaskSandbox $row) use ($key, $proxy): TaskSandbox {
        expect($proxy->keys)->not->toContain($key)->and($row->model_key)->toBeNull();
        $row->update(['state' => SandboxState::Destroyed]);

        return $row;
    });
    $lifecycle->review($sandbox, $driver, preview: true, capacityWaiting: false);
    $lifecycle->review($sandbox, $driver, preview: true, capacityWaiting: false);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Destroyed)->and($sandbox->fresh()->pi_token)->toBeNull()
        ->and($sandbox->fresh()->review_started_at->equalTo($started))->toBeTrue();
});

it('clears review timing only after activation is confirmed', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped', 'review_started_at' => now()->subMinutes(10), 'parked_at' => now()->subMinutes(5)]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('resume')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Starting, 'desired_power' => 'running']);

        return $row;
    });
    $driver->shouldReceive('resume')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Running]);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->activate($sandbox, $driver);
    expect($sandbox->fresh()->review_started_at)->not->toBeNull()->and($sandbox->fresh()->parked_at)->not->toBeNull();
    $lifecycle->activate($sandbox, $driver);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Running)
        ->and($sandbox->fresh()->review_started_at)->toBeNull()->and($sandbox->fresh()->parked_at)->toBeNull();
});

it('does not overlap a review transition with another lifecycle operation', function (): void {
    $sandbox = lifecycle_sandbox();
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('park', 'destroy');
    $lock = Cache::lock('orbit:compute:sandbox:'.$sandbox->id, 2400);
    $lock->get();
    try {
        expect(fn () => app(TaskSandboxLifecycle::class)->review($sandbox, $driver, preview: false, capacityWaiting: true))
            ->toThrow(ComputeException::class, 'lifecycle operation');
    } finally {
        $lock->release();
    }
    expect($sandbox->fresh()->review_started_at)->toBeNull();
});

it('requires fleet removal before expiring an enrolled UpCloud sandbox', function (): void {
    $sandbox = lifecycle_sandbox();
    $node = Node::query()->create(['name' => 'review-sandbox', 'platform' => 'linux', 'public_ssh_host' => '203.0.113.20']);
    $sandbox->update(['provider' => 'upcloud', 'node_id' => $node->id, 'state' => SandboxState::Stopped, 'desired_power' => 'stopped', 'review_started_at' => now()->subHour()]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('destroy');

    expect(fn () => app(TaskSandboxLifecycle::class)->review($sandbox, $driver, preview: false, capacityWaiting: false))
        ->toThrow(ComputeException::class, 'Remove the sandbox Node');
    expect($sandbox->fresh()->node_id)->toBe($node->id)->and($sandbox->fresh()->state)->toBe(SandboxState::Stopped);
});

it('does not grant a new retention window when a long-running preview is disabled', function (): void {
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['provider' => 'upcloud', 'state' => SandboxState::Running]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('destroy')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Destroyed]);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    $lifecycle->review($sandbox, $driver, preview: true, capacityWaiting: false);
    $this->travel(61)->minutes();
    $lifecycle->review($sandbox, $driver, preview: false, capacityWaiting: false);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Destroyed);
});

it('records parking only after the driver confirms that the VM stopped', function (): void {
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Running]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('park')->once()->andReturnUsing(function (TaskSandbox $row): TaskSandbox {
        $row->update(['state' => SandboxState::Stopping, 'desired_power' => 'stopped']);

        return $row;
    });
    app(TaskSandboxLifecycle::class)->review($sandbox, $driver, preview: false, capacityWaiting: true);

    expect($sandbox->fresh()->parked_at)->toBeNull()->and($sandbox->fresh()->state)->toBe(SandboxState::Stopping);
});

it('retains the review cycle when resume fails before compute starts', function (): void {
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $proxy->available = false;
    $this->travelTo(now()->startOfSecond());
    $started = now()->subMinutes(10);
    $parked = now()->subMinutes(5);
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped', 'review_started_at' => $started, 'parked_at' => $parked]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('resume');

    expect(fn () => app(TaskSandboxLifecycle::class)->activate($sandbox, $driver))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->state)->toBe(SandboxState::Stopped)
        ->and($sandbox->fresh()->review_started_at->equalTo($started))->toBeTrue()
        ->and($sandbox->fresh()->parked_at->equalTo($parked))->toBeTrue();
});

it('retries restoration after an uncertain resume instead of provisioning the parked reservation', function (): void {
    (new FakeSandboxModelProxy)->install();
    $sandbox = lifecycle_sandbox();
    $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);
    $driver = mock(ComputeDriver::class);
    $driver->shouldNotReceive('provision');
    $attempts = 0;
    $driver->shouldReceive('resume')->twice()->andReturnUsing(function (TaskSandbox $row) use (&$attempts): TaskSandbox {
        expect($row->resume_requested_at)->not->toBeNull();
        if ($attempts++ === 0) {
            $row->update(['state' => SandboxState::Uncertain, 'desired_power' => 'running']);
            throw new ComputeException('compute.capacity', 'The host VM budget is full.');
        }
        $row->update(['state' => SandboxState::Running, 'desired_power' => 'running']);

        return $row;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    expect(fn () => $lifecycle->activate($sandbox, $driver))->toThrow(ComputeException::class, 'budget');
    expect($sandbox->fresh()->resume_requested_at)->not->toBeNull();
    $lifecycle->activate($sandbox, $driver);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Running)->and($sandbox->fresh()->resume_requested_at)->toBeNull();
});

it('revokes model access before fleet removal and preserves provider deletion intent for retry', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $sandbox->forceFill(['model_proxy_origin' => 'http://127.0.0.1:28317'])->save();
    $proxy->keys = [$sandbox->model_key];
    $fleet = mock(SandboxFleetRemover::class);
    $fleet->shouldReceive('assertRemovable')->twice();
    $fleet->shouldReceive('remove')->twice()->andReturnUsing(function ($s) use ($workspace): void {
        expect($s->desired_power)->toBe('destroyed');
        expect($s->model_key)->toBeNull();
        if (Instance::query()->whereKey($workspace->id)->exists()) {
            $s->group->taskable()->dissociate();
            $s->group->save();
            $workspace->delete();
            $workspace->node->roles()->delete();
            $workspace->node->delete();
        }
    });
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('destroy')->once()->andThrow(new ComputeException('compute.unavailable', 'Provider deletion unavailable'));
    $driver->shouldReceive('destroy')->once()->andReturnUsing(function ($s) {
        expect($s->node_id)->toBeNull();
        $s->update(['state' => SandboxState::Destroyed]);

        return $s;
    });
    $lifecycle = app(TaskSandboxLifecycle::class);
    expect(fn () => $lifecycle->destroy($sandbox, $driver))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->desired_power)->toBe('destroyed');
    expect($sandbox->fresh()->pi_token)->not->toBeNull();
    $lifecycle->destroy($sandbox, $driver);
    expect($sandbox->fresh()->state)->toBe(SandboxState::Destroyed);
    expect($sandbox->fresh()->pi_token)->toBeNull();
});
