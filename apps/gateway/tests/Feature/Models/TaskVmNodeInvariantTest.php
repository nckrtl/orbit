<?php

declare(strict_types=1);

use App\Actions\Nodes\AddNodeAccessAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;

function invariant_node(string $name, int $octet): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => "192.0.2.{$octet}", 'wireguard_ip' => "10.44.0.{$octet}", 'user' => 'orbit',
    ]);
}

/** @param array<string, mixed> $attributes */
function invariant_instance(array $attributes): Instance
{
    return Instance::query()->create([...['checkout_path' => '/home/orbit/work', 'status' => InstanceState::Active], ...$attributes]);
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git']);
    $this->other = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git']);
    $this->host = invariant_node('beast', 7);
    $this->vmNode = invariant_node('tvm-1', 20);
    $this->shared = invariant_node('app-dev', 21);
    $this->group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $this->vm = TaskVm::query()->create([
        'group_id' => $this->group->id, 'host_node_id' => $this->host->id, 'node_id' => $this->vmNode->id, 'provider' => 'incus',
        'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.64.10', 'pi_token' => 'pi-token',
    ]);
});

it('places the group workspace on its task VM Node', function (): void {
    $workspace = invariant_instance(['project_id' => $this->project->id, 'node_id' => $this->vmNode->id, 'name' => TaskWorkspaceName::for($this->group)]);

    $workspace->update(['root' => 'web']);

    expect($workspace->fresh()?->node_id)->toBe($this->vmNode->id);
});

it('refuses a foreign Instance on a task VM Node', function (string $project, string $name): void {
    $projectId = $project === 'group' ? $this->project->id : $this->other->id;
    $name = $name === 'workspace' ? TaskWorkspaceName::for($this->group) : $name;

    expect(fn () => invariant_instance(['project_id' => $projectId, 'node_id' => $this->vmNode->id, 'name' => $name]))
        ->toThrow(TaskVmException::class, 'serves only the workspace');
    expect(Instance::query()->where('node_id', $this->vmNode->id)->exists())->toBeFalse();
})->with([
    'another Project' => ['other', 'workspace'],
    'another name' => ['group', 'main'],
]);

it('refuses moving an Instance onto a task VM Node', function (): void {
    $instance = invariant_instance(['project_id' => $this->other->id, 'node_id' => $this->shared->id, 'name' => 'main']);

    expect(fn () => $instance->update(['node_id' => $this->vmNode->id]))->toThrow(TaskVmException::class);
    expect($instance->fresh()?->node_id)->toBe($this->shared->id);
});

it('frees the Node once its task VM is destroyed', function (): void {
    $this->vm->update(['state' => TaskVmState::Destroyed]);

    $instance = invariant_instance(['project_id' => $this->other->id, 'node_id' => $this->vmNode->id, 'name' => 'main']);

    expect($instance->exists)->toBeTrue();
});

it('refuses an access edge from a task VM Node', function (): void {
    try {
        app(AddNodeAccessAction::class)->execute($this->vmNode, $this->shared);
        $this->fail('A task VM Node received an access edge.');
    } catch (TaskVmException $exception) {
        expect($exception->errorCode)->toBe('task_vm.access_refused')->and($exception->status)->toBe(409);
    }

    expect(NodeAccess::query()->where('consumer_node_id', $this->vmNode->id)->exists())->toBeFalse();
});

it('keeps access edges between other Nodes', function (): void {
    $added = app(AddNodeAccessAction::class)->execute($this->shared, $this->host);

    expect($added->alreadyExists)->toBeFalse()
        ->and(NodeAccess::query()->where('consumer_node_id', $this->shared->id)->exists())->toBeTrue();
});
