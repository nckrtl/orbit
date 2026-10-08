<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskTopology;
use App\Infrastructure\Compute\ComputeLocks;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;

/** Reserve additional owned workload VMs without replacing any existing guest. */
final readonly class ExpandTaskSandboxAction
{
    public function __construct(private TaskSandboxDrivers $drivers, private ComputeLocks $locks, private TaskExecutionLock $groups) {}

    /** @param list<string> $roles */
    public function execute(Instance $workspace, array $roles): TaskSandbox
    {
        $roles = TaskTopology::from($roles);
        $sandbox = $workspace->taskSandbox?->fresh();
        $group = $sandbox?->group;
        if ($sandbox === null || $group === null || $sandbox->provider !== 'incus'
            || $group->task_compute !== TaskCompute::Vm || $group->project->slug !== 'orbit'
            || $group->taskable_id !== $workspace->id || $group->taskable_type !== $workspace->getMorphClass()
            || $group->project_id !== $workspace->project_id) {
            throw new ComputeException('compute.topology_unavailable', 'Declared workload nodes require the owned Orbit VM sandbox.');
        }
        $group->requireManagedExecution();

        return $this->groups->synchronized($group->id, fn (): TaskSandbox => $this->locks->sandbox($sandbox->id, function () use ($sandbox, $group, $roles, $workspace): TaskSandbox {
            $group->refresh();
            $sandbox->refresh();
            $workspace->refresh();
            if ($workspace->task_sandbox_id !== $sandbox->id || $group->taskable_id !== $workspace->id
                || $group->taskable_type !== $workspace->getMorphClass() || $group->task_compute !== TaskCompute::Vm
                || TaskExecutionHold::active($group) || in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)
                || $sandbox->group_id !== $group->id || $sandbox->desired_power !== 'running'
                || in_array($sandbox->state, [SandboxState::Stopped, SandboxState::Stopping, SandboxState::Destroying, SandboxState::Destroyed], true)) {
                throw new ComputeException('compute.topology_unavailable', 'Resume the owned sandbox before adding workload nodes.');
            }
            $settings = array_find($this->drivers->localHosts(), fn (array $value): bool => $value['node_id'] === ($sandbox->spec['host_id'] ?? null));
            if ($settings === null || $settings['project'] !== ($sandbox->spec['project'] ?? null)
                || ! is_array($sandbox->spec['source_template'] ?? null)) {
                throw new ComputeException('compute.topology_unavailable', 'The sandbox host and pinned source template must be available.');
            }
            $images = $sandbox->spec['images'] ?? null;
            if (! is_array($images) || ! isset($images['operator'], $images['gateway'])
                || array_diff(array_keys($images), ['operator', 'gateway', ...TaskTopology::Roles]) !== []) {
                throw new ComputeException('compute.topology_unavailable', 'The recorded sandbox inventory is invalid.');
            }
            $missing = array_values(array_diff($roles, array_keys($images)));
            foreach ($missing as $role) {
                if (! isset($settings['orbit_images'][$role])) {
                    throw new ComputeException('compute.topology_image_missing', 'No pinned image is configured for workload node '.$role.'.');
                }
            }
            if ($missing !== [] && (($settings['orbit_source_template'] ?? null) != $sandbox->spec['source_template']
                || ($settings['orbit_images']['operator'] ?? null) !== $images['operator']
                || ($settings['orbit_images']['gateway'] ?? null) !== $images['gateway'])) {
                throw new ComputeException('compute.topology_image_missing', 'Restore host images from the sandbox’s recorded template before adding workload nodes.');
            }
            $driver = $this->drivers->forSandbox($sandbox);
            if ($missing !== [] && $driver->capacity() < count($missing)) {
                throw new ComputeException('compute.capacity', 'The local VM budget has no capacity for the declared workload nodes.');
            }
            if ($missing !== []) {
                DB::transaction(function () use ($sandbox, $settings, $missing): void {
                    // This is the same host-row lock used by initial placement. Remote calls stay outside it.
                    Node::query()->lockForUpdate()->findOrFail($settings['node_id']);
                    $reserved = TaskSandbox::query()->where('provider', 'incus')->where('state', '!=', SandboxState::Destroyed->value)->get()
                        ->filter(fn (TaskSandbox $row): bool => ($row->spec['host_id'] ?? null) === $settings['node_id'])
                        ->sum(function (TaskSandbox $row) use ($settings): int {
                            if ($row->state === SandboxState::Stopped && $row->desired_power === 'stopped') {
                                return 0;
                            }
                            $images = $row->spec['images'] ?? null;

                            return is_array($images) && $images !== [] ? count($images) : $settings['max_vms'];
                        });
                    if (count($missing) > $settings['max_vms'] - $reserved) {
                        throw new ComputeException('compute.capacity', 'The local VM budget is reserved by other task sandboxes.');
                    }
                    $spec = $sandbox->spec;
                    $images = $spec['images'];
                    if (! is_array($images)) {
                        throw new ComputeException('compute.topology_unavailable', 'The recorded sandbox inventory changed.');
                    }
                    foreach ($missing as $role) {
                        $images[$role] = $settings['orbit_images'][$role];
                    }
                    $sandbox->update(['spec' => [...$spec, 'images' => $images]]);
                });
            }
            // A partial host failure retains the expanded reservation. Retry provisions only missing guests.
            $result = $driver->provision($sandbox);
            if ($result->state !== SandboxState::Running) {
                throw new ComputeException('compute.topology_starting', 'The declared workload VMs are not all running yet.');
            }

            return $result;
        }));
    }
}
