<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

function compute_project(): Project
{
    return Project::query()->create([
        'name' => 'Sandbox', 'slug' => 'sandbox', 'repository_url' => 'https://github.com/acme/sandbox.git',
        'default_branch' => 'main', 'root' => 'public', 'task_check' => 'true',
    ]);
}

describe('task compute rollout', function (): void {
    it('keeps shared as the default and changes only future claims through the Project API', function (): void {
        $gateway = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
        $project = compute_project();
        $running = Task::topLevel()->create([
            'project_id' => $project->id, 'title' => 'Existing work', 'brief' => 'Keep the workspace.',
            'status' => TaskGroupStatus::Running, 'task_compute' => TaskCompute::Shared,
        ]);
        expect($project->task_compute)->toBe(TaskCompute::Shared);

        $this->patchJson('/api/v1/projects/'.$project->id, ['task_compute' => 'vm'])
            ->assertOk()->assertJsonPath('data.task_compute', 'vm');

        expect($project->fresh()->task_compute)->toBe(TaskCompute::Vm);
        expect($running->fresh()->task_compute)->toBe(TaskCompute::Shared);
        $this->patchJson('/api/v1/projects/'.$project->id, ['task_compute' => 'automatic'])->assertUnprocessable();
        expect($project->fresh()->task_compute)->toBe(TaskCompute::Vm);
    });

    it('pins a VM claim and waits visibly without creating a shared workspace', function (): void {
        $project = compute_project();
        $project->update(['task_compute' => TaskCompute::Vm]);
        $group = Task::topLevel()->create([
            'project_id' => $project->id, 'title' => 'VM task', 'brief' => 'Isolated work.', 'status' => TaskGroupStatus::Todo,
        ]);
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'Work.', 'status' => TaskStatus::Todo]);

        expect(app(TaskScheduler::class)->claimNext())->toBeNull();

        expect($group->fresh()->task_compute)->toBe(TaskCompute::Vm)
            ->and($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->fresh()->capacity_wait_reason)->toContain('sandbox compute')
            ->and(Instance::query()->count())->toBe(0);
        $project->update(['task_compute' => TaskCompute::Shared]);
        expect(fn () => $group->fresh()->update(['task_compute' => TaskCompute::Shared]))
            ->toThrow(LogicException::class, 'cannot change compute mode');
        expect($group->fresh()->task_compute)->toBe(TaskCompute::Vm);
    });
});
