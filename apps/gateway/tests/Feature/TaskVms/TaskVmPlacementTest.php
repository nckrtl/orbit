<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;

function task_vm_node(string $name, int $octet): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => "192.0.2.{$octet}", 'wireguard_ip' => "10.44.0.{$octet}", 'user' => 'orbit',
    ]);
}

function task_vm_group(Project $project, TaskCompute $compute = TaskCompute::Vm): Task
{
    return Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => $compute]);
}

function task_vm_row(Task $group, Node $host, ?Node $node, TaskVmState $state = TaskVmState::Ready, string $wireguardIp = '10.44.0.130'): TaskVm
{
    return TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $node?->id, 'provider' => 'incus',
        'name' => 'tvm-'.$group->id.'-'.$state->value, 'state' => $state, 'wireguard_ip' => $wireguardIp, 'pi_token' => 'pi-token',
    ]);
}

/** An unsaved Instance: placement reads only its Node, Project and name. */
function task_vm_instance(Project $project, Node $node, string $name): Instance
{
    return new Instance()->forceFill(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name]);
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->other = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main']);
    $this->host = task_vm_node('beast', 7);
    $this->vmNode = task_vm_node('tvm-1', 20);
    $this->shared = task_vm_node('app-dev', 21);
    $this->group = task_vm_group($this->project);
});

describe('forNode', function (): void {
    it('finds the live task VM of a Node', function (): void {
        $vm = task_vm_row($this->group, $this->host, $this->vmNode);

        expect(TaskVmPlacement::forNode($this->vmNode)?->is($vm))->toBeTrue()
            ->and(TaskVmPlacement::forNode($this->host))->toBeNull()
            ->and(TaskVmPlacement::forGroup($this->group)?->is($vm))->toBeTrue();
    });

    it('ignores destroyed task VMs', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode, TaskVmState::Destroyed);

        expect(TaskVmPlacement::forNode($this->vmNode))->toBeNull()
            ->and(TaskVmPlacement::forGroup($this->group))->toBeNull();
    });
});

describe('assertInstance', function (): void {
    it('allows the group workspace on its task VM and any Instance on other Nodes', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode, TaskVmState::Provisioning);

        TaskVmPlacement::assertInstance(task_vm_instance($this->project, $this->vmNode, 'task-'.$this->group->id));
        TaskVmPlacement::assertInstance(task_vm_instance($this->other, $this->shared, 'main'));

        expect(true)->toBeTrue();
    });

    it('refuses any other Instance on a task VM Node', function (string $project, string $name): void {
        task_vm_row($this->group, $this->host, $this->vmNode);
        $instance = task_vm_instance($this->{$project}, $this->vmNode, str_replace('{group}', (string) $this->group->id, $name));

        expect(fn () => TaskVmPlacement::assertInstance($instance))
            ->toThrow(TaskVmException::class, "Node [{$this->vmNode->id}] is a task VM and serves only the workspace of task group [{$this->group->id}].");
    })->with([
        'other Project' => ['other', 'task-{group}'],
        'other name' => ['project', 'main'],
        'other group workspace' => ['project', 'task-999'],
    ]);

    it('still protects a task VM that is being destroyed or failed', function (TaskVmState $state): void {
        task_vm_row($this->group, $this->host, $this->vmNode, $state);

        expect(fn () => TaskVmPlacement::assertInstance(task_vm_instance($this->other, $this->vmNode, 'main')))
            ->toThrow(TaskVmException::class);
    })->with([TaskVmState::Destroying, TaskVmState::Failed]);
});

describe('assertWorkspace', function (): void {
    it('accepts a vm group workspace on its ready task VM', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode);

        TaskVmPlacement::assertWorkspace($this->group, task_vm_instance($this->project, $this->vmNode, 'task-'.$this->group->id));

        expect(true)->toBeTrue();
    });

    it('refuses a vm group workspace that is not on its ready task VM', function (?TaskVmState $state, string $node, string $name): void {
        if ($state instanceof TaskVmState) {
            task_vm_row($this->group, $this->host, $this->vmNode, $state);
        }
        $workspace = task_vm_instance($this->project, $this->{$node}, str_replace('{group}', (string) $this->group->id, $name));

        expect(fn () => TaskVmPlacement::assertWorkspace($this->group, $workspace))
            ->toThrow(TaskVmException::class, "Task group [{$this->group->id}] has no workspace on its ready task VM.");
    })->with([
        'no task VM' => [null, 'vmNode', 'task-{group}'],
        'still provisioning' => [TaskVmState::Provisioning, 'vmNode', 'task-{group}'],
        'failed' => [TaskVmState::Failed, 'vmNode', 'task-{group}'],
        'shared Node' => [TaskVmState::Ready, 'shared', 'task-{group}'],
        'wrong name' => [TaskVmState::Ready, 'vmNode', 'main'],
    ]);

    it('refuses a shared group workspace on a task VM Node', function (): void {
        task_vm_row($this->group, $this->host, $this->vmNode);
        $shared = task_vm_group($this->project, TaskCompute::Shared);

        expect(fn () => TaskVmPlacement::assertWorkspace($shared, task_vm_instance($this->project, $this->vmNode, 'task-'.$shared->id)))
            ->toThrow(TaskVmException::class, "Task group [{$shared->id}] does not own the task VM that serves its workspace.");
    });

    it('accepts a shared group workspace on a shared Node', function (): void {
        $shared = task_vm_group($this->project, TaskCompute::Shared);

        TaskVmPlacement::assertWorkspace($shared, task_vm_instance($this->project, $this->shared, 'task-'.$shared->id));

        expect(true)->toBeTrue();
    });
});
