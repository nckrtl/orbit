<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\ForgetTaskVmWorkspacesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Destroys a task VM after its workspace is gone: `destroying`, model key revoked, VM deleted, the Node
 * removed offline, and only then `destroyed`. A row that is not `destroyed` keeps its Node a task VM
 * Node, so Pi never falls back to the Gateway-wide token. A failed step stays on the row as its error,
 * and the job runs again until it succeeds.
 *
 * A VM that died before its workspace could be removed leaves the workspace behind: removal needs SSH to
 * the VM. Once that removal has failed for an ended group and the VM is absent or stays stopped, the job
 * deletes the VM and then forgets the workspace records offline.
 *
 * The unique lock has no expiry, so the tick never queues a second copy while one waits or runs.
 */
final class DestroyTaskVm implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 900;

    public function __construct(public int $taskVmId)
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }

    public function handle(TaskVmRuntime $runtime, TaskVmProvider $provider, RemoveNodeAction $nodes, ForgetTaskVmWorkspacesAction $workspaces, RemoveTaskWorkspaceAction $removal): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if (! $vm instanceof TaskVm || $vm->state === TaskVmState::Destroyed) {
            return;
        }
        $workspace = $vm->node_id !== null && Instance::query()->where('node_id', $vm->node_id)->exists();
        // Normal workspace removal goes first. Only a removal that failed against a VM that is gone lets destroy go on.
        if ($workspace && $vm->state !== TaskVmState::Destroying && (! self::removalFailed($vm->group) || $this->runs($provider, $vm))) {
            return;
        }

        try {
            $vm->update(['state' => TaskVmState::Destroying, 'error_code' => null, 'error_message' => null]);
            $runtime->release($vm);
            $provider->destroy($vm);
            if ($workspace) {
                $workspaces->execute($vm);
                $removal->clearFailure($vm->group);
            }
            $node = $vm->node()->first();
            if ($node instanceof Node) {
                // The VM is gone, so the Node is unreachable: offline removal sheds its role and forgets its Processes.
                $nodes->execute($node, $this->gateway(), offline: true, force: true);
            }
            $vm->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now()]);
        } catch (Throwable $exception) {
            $vm->update(['error_code' => TaskVmException::codeOf($exception), 'error_message' => mb_substr($exception->getMessage(), 0, 2000)]);

            throw $exception;
        }
    }

    /** Set by a failed workspace removal of an ended group, or of a merged group's cleanup. */
    public static function removalFailed(Task $group): bool
    {
        $reason = (string) $group->assistance_reason;

        return str_starts_with($reason, RemoveTaskWorkspaceAction::RemovalFailedPrefix) || str_starts_with($reason, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix);
    }

    /** Whether the VM runs. A guest reboot shows it stopped for about a second, so a stopped VM gets a second reading. */
    private function runs(TaskVmProvider $provider, TaskVm $vm): bool
    {
        $observation = $provider->observe($vm);
        if ($observation?->running === false) {
            Sleep::for(5)->seconds();
            $observation = $provider->observe($vm);
        }

        return $observation?->running === true;
    }

    private function gateway(): Node
    {
        return Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
            ->first() ?? throw new TaskVmException('task_vm.job_failed', 'No active Gateway Node can remove the task VM Node.', 500);
    }
}
