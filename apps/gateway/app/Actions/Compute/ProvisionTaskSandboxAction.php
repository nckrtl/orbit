<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Compute\ComputeLocks;
use App\Infrastructure\Compute\TaskSandboxLifecycle;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;

final readonly class ProvisionTaskSandboxAction
{
    public function __construct(private ComputeDriver $driver, private ComputeLocks $locks, private SshKeyProvider $keys, private TaskSandboxLifecycle $lifecycle) {}

    public function execute(Task $group): TaskSandbox
    {
        $group->requireManagedExecution();
        if (! $group->exists || $group->parent_id !== null) {
            throw new ComputeException('compute.invalid_group', 'A sandbox requires a persisted managed task group.');
        }
        $sandbox = $this->locks->upcloud(function () use ($group): TaskSandbox {
            $group->refresh();
            if (in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)) {
                throw new ComputeException('compute.invalid_group', 'An ended task group cannot provision a sandbox.');
            }
            $existing = TaskSandbox::query()->where('group_id', $group->id)
                ->where('state', '!=', SandboxState::Destroyed->value)->first();
            if ($existing instanceof TaskSandbox) {
                return $existing;
            }
            if ($group->taskable_id !== null) {
                throw new ComputeException('compute.placement_conflict', 'The task group already has a workspace; its placement cannot change.');
            }
            if (! config('compute.upcloud.enabled', false)) {
                throw new ComputeException('compute.disabled', 'UpCloud compute is disabled.');
            }
            if ($this->driver->capacity() < 1) {
                throw new ComputeException('compute.capacity', 'The UpCloud VM budget is full.');
            }
            $spec = SandboxSpec::fromArray([
                'image' => config('compute.upcloud.image'), 'size' => 'starter-small',
                'zone' => config('compute.upcloud.zone'), 'gateway_address' => config('compute.upcloud.gateway_address'),
                'wireguard_address' => config('compute.upcloud.wireguard_address'), 'wireguard_port' => config('compute.upcloud.wireguard_port'),
                'public_key' => trim($this->keys->publicKey()),
            ]);
            $id = (string) Str::uuid();

            return TaskSandbox::query()->create([
                'id' => $id, 'group_id' => $group->id, 'provider' => 'upcloud', 'name' => 'orbit-sandbox-'.$id,
                'state' => SandboxState::Reserved, 'desired_power' => 'running', 'spec' => $spec->toArray(),
            ]);
        });

        return $this->lifecycle->activate($sandbox, $this->driver);
    }
}
