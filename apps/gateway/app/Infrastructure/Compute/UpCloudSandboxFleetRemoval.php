<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxFleetRemover;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;

/** Remove only the enrolled fleet footprint pinned to this reservation. */
final readonly class UpCloudSandboxFleetRemoval implements SandboxFleetRemover
{
    public function __construct(private SandboxFleetIdentity $identity, private RemoveNodeRoleAction $roles,
        private RemoveNodeAction $nodes, private SandboxNetworkPolicy $network) {}

    public function assertRemovable(TaskSandbox $sandbox): void
    {
        if ($sandbox->provider !== 'upcloud' || $sandbox->enrollment === null) {
            throw new ComputeException('compute.node_attached', 'Remove the sandbox Node from the fleet before destroying its VM.');
        }
        $group = $sandbox->group;
        if ($group !== null && ($group->task_compute !== TaskCompute::Vm || $group->execution_mode !== TaskExecutionMode::Managed || $group->project->slug === 'orbit')) {
            throw $this->ownership();
        }
        $node = $sandbox->node_id === null ? null : Node::query()->find($sandbox->node_id);
        if ($sandbox->node_id !== null && $node === null) {
            throw $this->ownership();
        }
        if ($node !== null) {
            $this->identity->assertOwned($sandbox, $node);
            if ($node->instances()->where('task_sandbox_id', '!=', $sandbox->id)->exists()
                || $node->instances()->whereNull('task_sandbox_id')->exists() || $node->processes()->exists()
                || $node->schedules()->exists() || DatabaseServer::query()->where('node_id', $node->id)->exists()) {
                throw $this->ownership();
            }
        } elseif (Node::query()->where('compute_sandbox_id', $sandbox->id)->exists()) {
            throw $this->ownership();
        }
        $workspaces = Instance::query()->where('task_sandbox_id', $sandbox->id)->get();
        if ($workspaces->count() > 1 || ($workspaces->isEmpty() && $group?->taskable_id !== null)) {
            throw $this->ownership();
        }
        foreach ($workspaces as $workspace) {
            if ($group === null || $node === null || $workspace->node_id !== $node->id || $workspace->project_id !== $group->project_id
                || $workspace->name !== TaskWorkspaceName::for($group) || $workspace->branch_override !== $workspace->name
                || $workspace->checkout_path !== '/home/orbit/orbit' || $workspace->task_workspace_routed !== false
                || ($group->taskable_id !== null && ($group->taskable_id !== $workspace->id || $group->taskable_type !== $workspace->getMorphClass()))
                || Task::withoutGlobalScope('subtask')->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)->whereKeyNot($group->id)->exists()
                || $workspace->routeTargets()->exists() || $workspace->processes()->exists() || $workspace->schedules()->exists()
                || $workspace->databaseConnectionTargets()->exists() || $workspace->removalMember()->exists() || $workspace->transfers()->exists()) {
                throw $this->ownership();
            }
        }
    }

    public function remove(TaskSandbox $sandbox): void
    {
        $this->assertRemovable($sandbox);
        if ($sandbox->desired_power !== 'destroyed' || $sandbox->model_key !== null) {
            throw new ComputeException('compute.cleanup_pending', 'Record destruction intent and revoke the model key before fleet removal.');
        }
        $node = $sandbox->node_id === null ? null : Node::query()->find($sandbox->node_id);
        if ($node !== null) {
            $gatewayId = $sandbox->enrollment['gateway_id'] ?? null;
            $gateway = is_int($gatewayId) ? Node::query()->find($gatewayId) : null;
            if ($gateway === null || $gateway->status !== LifecycleStatus::Active
                || ! $gateway->roles()->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active)->exists()) {
                throw $this->ownership();
            }
            DB::transaction(function () use ($sandbox): void {
                $this->assertRemovable($sandbox);
                foreach (Instance::query()->where('task_sandbox_id', $sandbox->id)->get() as $workspace) {
                    Task::withoutGlobalScope('subtask')->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)
                        ->update(['taskable_type' => null, 'taskable_id' => null]);
                    $workspace->delete();
                }
            });
            if ($node->roles()->where('role', RoleName::AppDev)->exists()) {
                $this->roles->execute($node, RoleName::AppDev, force: true, offline: true);
            }
            $this->nodes->execute($node->refresh(), $gateway, offline: true, force: true);
            $sandbox->refresh();
        }
        if ($sandbox->node_id !== null) {
            throw $this->ownership();
        }
        $this->network->remove($sandbox);
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The sandbox fleet cleanup does not have exclusive ownership.');
    }
}
