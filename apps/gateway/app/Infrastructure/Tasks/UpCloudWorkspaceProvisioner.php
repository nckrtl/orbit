<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Actions\Compute\EnrollUpCloudSandboxAction;
use App\Actions\Compute\ProvisionTaskSandboxAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;

/** First project lane: one enrolled UpCloud VM, without local-placement fallback. */
final readonly class UpCloudWorkspaceProvisioner
{
    public function __construct(private TaskExecutionLock $groups, private ProvisionTaskSandboxAction $compute,
        private EnrollUpCloudSandboxAction $enroll, private SandboxFleetIdentity $identity,
        private SandboxWorkspaceSource $source, private SandboxPiRuntime $pi, private SandboxPiArtifact $artifact) {}

    public function provision(Task $reserved): Instance
    {
        return $this->groups->synchronized($reserved->id, function () use ($reserved): Instance {
            $group = Task::topLevel()->with(['project', 'taskable'])->findOrFail($reserved->id);
            $this->assertClaim($group, $reserved);
            if (! config('compute.project_claims_enabled', false)) {
                throw new ComputeException('compute.not_ready', 'The Project sandbox lane is not ready on this Gateway. Project sandbox compute is disabled.');
            }
            if (! config('compute.upcloud.enabled', false) || ! config('compute.upcloud.enrollment_enabled', false)
                || ! config('compute.model_proxy.enabled', false) || ! is_array(config('compute.pi.models')) || config('compute.pi.models') === []
                || $group->implementer_agent_driver !== 'pi' || $group->reviewer_agent_driver !== 'pi') {
                throw new ComputeException('compute.not_ready', 'Project claims require UpCloud enrollment, model credentials, and Pi configuration.');
            }
            $this->artifact->assertConfigured();
            $existing = TaskSandbox::query()->where('group_id', $group->id)->where('state', '!=', SandboxState::Destroyed)->first();
            if ($existing !== null && $existing->provider !== 'upcloud') {
                throw new ComputeException('compute.placement_conflict', 'The project reservation has another placement.');
            }
            if ($group->taskable_id !== null) {
                if (! $group->taskable instanceof Instance || $existing === null) {
                    throw $this->ownership();
                }
                $this->assertWorkspace($group->taskable, $group, $existing);
            } elseif (Instance::query()->where('project_id', $group->project_id)->where('name', TaskWorkspaceName::for($group))->exists()) {
                throw $this->ownership();
            }
            $sandbox = $this->compute->execute($group);
            if ($sandbox->provider !== 'upcloud' || $sandbox->group_id !== $group->id
                || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running') {
                throw new ComputeException('compute.starting', 'The project sandbox is still starting.');
            }
            if ($sandbox->enrolled_at === null) {
                $node = $this->enroll->execute($sandbox);
            } else {
                $node = Node::query()->find($sandbox->node_id);
                if ($node === null) {
                    throw $this->ownership();
                }
                $this->identity->assertReady($sandbox, $node);
            }
            $sandbox->refresh();
            DB::transaction(function () use ($group, $reserved, $sandbox, $node): void {
                $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                $this->assertClaim($locked, $reserved);
                if ($locked->taskable_id !== null) {
                    if (! $locked->taskable instanceof Instance) {
                        throw $this->ownership();
                    }
                    $this->assertWorkspace($locked->taskable, $locked, $sandbox);

                    return;
                }
                if (Instance::query()->where('task_sandbox_id', $sandbox->id)->exists()
                    || Instance::query()->where('project_id', $group->project_id)->where('name', TaskWorkspaceName::for($group))->exists()) {
                    throw $this->ownership();
                }
                $workspace = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $node->id,
                    'name' => TaskWorkspaceName::for($group), 'branch_override' => TaskWorkspaceName::for($group),
                    'checkout_path' => '/home/orbit/orbit', 'source_layout' => InstanceSourceLayout::Checkout,
                    'task_workspace_routed' => false, 'task_sandbox_id' => $sandbox->id, 'status' => InstanceState::Reserved]);
                $locked->taskable()->associate($workspace);
                $locked->save();
            });
            $workspace = $this->source->prepare($group->refresh());
            $this->pi->prepare($workspace);
            $this->assertClaim($group->refresh(), $reserved);

            return $workspace->refresh();
        });
    }

    private function assertClaim(Task $group, Task $reserved): void
    {
        if ($group->project->slug === 'orbit' || $group->execution_mode !== TaskExecutionMode::Managed || $group->task_compute !== TaskCompute::Vm
            || $group->status !== TaskGroupStatus::Reserved || TaskExecutionHold::active($group) || $group->reserved_at === null
            || $reserved->reserved_at === null || ! $group->reserved_at->equalTo($reserved->reserved_at)) {
            throw new ComputeException('compute.claim_changed', 'The project sandbox claim is no longer current.');
        }
    }

    private function assertWorkspace(Instance $workspace, Task $group, TaskSandbox $sandbox): void
    {
        if ($workspace->project_id !== $group->project_id || $workspace->task_sandbox_id !== $sandbox->id
            || $sandbox->node_id === null || $workspace->node_id !== $sandbox->node_id || $workspace->checkout_path !== '/home/orbit/orbit'
            || $workspace->name !== TaskWorkspaceName::for($group) || $workspace->branch_override !== $workspace->name || $workspace->task_workspace_routed !== false
            || ! in_array($workspace->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved], true)
            || Task::withoutGlobalScope('subtask')->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)->whereKeyNot($group->id)->exists()) {
            throw $this->ownership();
        }
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The project workspace does not match its sandbox reservation.');
    }
}
