<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Actions\Compute\AllocateTaskSandboxAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Admit guest work only after an owned pair and its runtime are ready. */
final readonly class SandboxWorkspaceProvisioner
{
    public function __construct(
        private TaskExecutionLock $execution,
        private AllocateTaskSandboxAction $allocate,
        private TaskSandboxDrivers $drivers,
        private SandboxWorkspaceSource $source,
        private SandboxPairRuntime $pair,
        private SandboxPiRuntime $pi,
        private ProjectSandboxWorkspaceProvisioner $projects,
    ) {}

    public function provision(Task $reserved): Instance|InstanceProvisionFailure
    {
        return $this->execution->synchronized($reserved->id, function () use ($reserved): Instance|InstanceProvisionFailure {
            try {
                $group = Task::topLevel()->with(['project', 'taskable'])->findOrFail($reserved->id);
                $this->assertClaim($group, $reserved);
                if ($group->project->slug !== 'orbit') {
                    return $this->projects->provision($reserved);
                }
                if (! config('compute.orbit_claims_enabled', false)) {
                    throw new TaskCapacityException(false, 'Task sandbox compute is not configured on this Gateway.');
                }
                if ($group->implementer_agent_driver !== 'pi' || $group->reviewer_agent_driver !== 'pi') {
                    throw new TaskCapacityException(false, 'Sandbox claims require Pi for implementation and review.');
                }
                $this->prerequisites();
                $existing = TaskSandbox::query()->where('group_id', $group->id)->where('state', '!=', SandboxState::Destroyed->value)->first();
                if ($existing instanceof TaskSandbox) {
                    $this->assertSandbox($existing, $group);
                }
                if ($group->taskable_id !== null) {
                    if (! $existing instanceof TaskSandbox || ! $group->taskable instanceof Instance) {
                        throw new ComputeException('compute.ownership_mismatch', 'The claim already has another workspace.');
                    }
                    $this->assertWorkspace($group->taskable, $group, $existing);
                } elseif (Instance::query()->where('project_id', $group->project_id)->where('name', TaskWorkspaceName::for($group))->exists()) {
                    throw new ComputeException('compute.ownership_mismatch', 'The task workspace name is already occupied.');
                }
                $sandbox = $this->allocate->execute($group);
                $this->assertSandbox($sandbox, $group);
                if ($sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running') {
                    throw new ComputeException('compute.starting', 'The task sandbox is still starting.');
                }
                $workspace = DB::transaction(function () use ($group, $reserved, $sandbox): Instance {
                    $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                    $this->assertClaim($locked, $reserved);
                    if ($locked->taskable_id !== null) {
                        $workspace = $locked->taskable;
                        if (! $workspace instanceof Instance) {
                            throw new ComputeException('compute.ownership_mismatch', 'The claim already has another workspace.');
                        }
                        $this->assertWorkspace($workspace, $locked, $sandbox);

                        return $workspace;
                    }
                    if (Instance::query()->where('task_sandbox_id', $sandbox->id)->exists()
                        || Instance::query()->where('project_id', $group->project_id)->where('name', TaskWorkspaceName::for($group))->exists()) {
                        throw new ComputeException('compute.ownership_mismatch', 'The sandbox workspace is already occupied.');
                    }
                    $workspace = Instance::query()->create([
                        'project_id' => $group->project_id, 'node_id' => $sandbox->spec['host_id'],
                        'name' => TaskWorkspaceName::for($group), 'branch_override' => TaskWorkspaceName::for($group),
                        'checkout_path' => '/home/orbit/orbit', 'source_layout' => InstanceSourceLayout::Checkout,
                        'task_workspace_routed' => false, 'task_sandbox_id' => $sandbox->id, 'status' => InstanceState::Reserved,
                    ]);
                    $locked->taskable()->associate($workspace);
                    $locked->save();

                    return $workspace;
                });
                $workspace = $this->source->prepare($group->refresh());
                $this->pair->prepare($workspace);
                $this->pi->prepare($workspace);
                $this->assertClaim($group->refresh(), $reserved);

                return $workspace->refresh();
            } catch (ComputeException $exception) {
                throw new TaskCapacityException(false, 'Sandbox compute: '.$exception->getMessage());
            } catch (TaskCapacityException $exception) {
                throw $exception;
            } catch (Throwable) {
                return new InstanceProvisionFailure('Sandbox preparation failed. Its owned workspace is retained for retry.');
            }
        });
    }

    private function assertClaim(Task $group, Task $reserved): void
    {
        if ($group->execution_mode !== TaskExecutionMode::Managed || $group->task_compute !== TaskCompute::Vm
            || $group->status !== TaskGroupStatus::Reserved || TaskExecutionHold::active($group)
            || $group->reserved_at === null || $reserved->reserved_at === null || ! $group->reserved_at->equalTo($reserved->reserved_at)) {
            throw new ComputeException('compute.claim_changed', 'The task sandbox claim is no longer current.');
        }
    }

    private function prerequisites(): void
    {
        $models = config('compute.pi.models');
        if (! config('compute.incus.enabled', false) || ! config('compute.model_proxy.enabled', false) || ! is_array($models) || $models === []) {
            throw new ComputeException('compute.not_ready', 'Enable local compute and configure the sandbox model proxy and Pi models before claiming.');
        }
        $eligible = 0;
        foreach ($this->drivers->localHosts() as $host) {
            if (! isset($host['orbit_images']['operator'], $host['orbit_images']['gateway'])) {
                continue;
            }
            $eligible++;
            if ($host['orbit_source_template'] === null || $host['gateway_address'] === null || $host['model_proxy_origin'] === null) {
                throw new ComputeException('compute.not_ready', 'Each Orbit compute host needs a source template, private Pi access, and model relay.');
            }
        }
        if ($eligible === 0) {
            throw new ComputeException('compute.not_ready', 'No local host has an Orbit sandbox pair image.');
        }
    }

    private function assertSandbox(TaskSandbox $sandbox, Task $group): void
    {
        if ($sandbox->group_id !== $group->id || $sandbox->provider !== 'incus' || $sandbox->node_id !== null
            || ! is_int($sandbox->spec['host_id'] ?? null) || ! is_array($sandbox->spec['source_template'] ?? null)
            || ! is_array($sandbox->spec['images'] ?? null) || ! isset($sandbox->spec['images']['operator'], $sandbox->spec['images']['gateway'])
            || ! is_string($sandbox->spec['pi_host'] ?? null) || ! is_int($sandbox->spec['pi_port'] ?? null)
            || ! is_string($sandbox->spec['model_proxy_origin'] ?? null)) {
            throw new ComputeException('compute.not_ready', 'The recorded sandbox lacks the Orbit pair runtime prerequisites.');
        }
    }

    private function assertWorkspace(Instance $workspace, Task $group, TaskSandbox $sandbox): void
    {
        if ($workspace->project_id !== $group->project_id || $workspace->task_sandbox_id !== $sandbox->id
            || $workspace->node_id !== $sandbox->spec['host_id'] || $workspace->checkout_path !== '/home/orbit/orbit'
            || $workspace->name !== TaskWorkspaceName::for($group) || $workspace->branch_override !== $workspace->name
            || $workspace->task_workspace_routed !== false
            || ! in_array($workspace->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved], true)
            || Task::withoutGlobalScope('subtask')->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)->whereKeyNot($group->id)->exists()) {
            throw new ComputeException('compute.ownership_mismatch', 'The claim workspace does not match its sandbox reservation.');
        }
    }
}
