<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $this->host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
    ]);
    $this->vm = fn (array $attributes = []): TaskVm => TaskVm::query()->create([
        'group_id' => $this->group->id, 'host_node_id' => $this->host->id, 'provider' => 'incus', 'name' => 'tvm-'.$this->group->id,
        'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.64.10', 'pi_token' => 'pi-secret', ...$attributes,
    ]);
});

it('casts the state and timestamps', function (): void {
    ($this->vm)(['state' => TaskVmState::Ready, 'ready_at' => '2026-10-09 12:00:00']);

    $vm = TaskVm::query()->sole();

    expect($vm->state)->toBe(TaskVmState::Ready)
        ->and($vm->ready_at?->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and($vm->group->is($this->group))->toBeTrue()
        ->and($vm->hostNode->is($this->host))->toBeTrue()
        ->and($vm->node)->toBeNull();
});

it('encrypts the Pi token and model key at rest and hides them', function (): void {
    ($this->vm)(['model_key' => 'model-secret']);

    $row = DB::table('task_vms')->sole();
    $vm = TaskVm::query()->sole();

    expect($row->pi_token)->not->toContain('pi-secret')
        ->and(Crypt::decryptString($row->pi_token))->toBe('pi-secret')
        ->and($row->model_key)->not->toContain('model-secret')
        ->and($vm->pi_token)->toBe('pi-secret')
        ->and($vm->model_key)->toBe('model-secret')
        ->and($vm->toArray())->not->toHaveKeys(['pi_token', 'model_key']);
});

it('allows one live task VM per group and per WireGuard address', function (): void {
    ($this->vm)();

    expect(fn () => ($this->vm)(['name' => 'tvm-second', 'wireguard_ip' => '10.44.64.11']))->toThrow(QueryException::class);

    TaskVm::query()->update(['state' => TaskVmState::Destroyed]);
    $next = ($this->vm)(['name' => 'tvm-second']);

    expect(TaskVm::query()->live()->sole()->is($next))->toBeTrue();
});

it('keeps the destroyed row when its Node is removed', function (): void {
    $node = Node::query()->create([
        'name' => 'tvm-1', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.64.10', 'user' => 'orbit',
    ]);
    $vm = ($this->vm)(['node_id' => $node->id, 'state' => TaskVmState::Destroying]);

    $node->delete();

    expect($vm->fresh()?->node_id)->toBeNull();
});
