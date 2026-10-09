<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Destroys a task VM after its workspace is gone: `destroying`, model key revoked, VM deleted, the Node
 * removed offline, and only then `destroyed`. A row that is not `destroyed` keeps its Node a task VM
 * Node, so Pi never falls back to the Gateway-wide token. A failed step stays on the row as its error,
 * and the job runs again until it succeeds.
 */
final class DestroyTaskVm implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public int $taskVmId)
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }

    public function handle(TaskVmRuntime $runtime, TaskVmProvider $provider, RemoveNodeAction $nodes): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        // The workspace goes first. The tick queues this job again once it is gone.
        if (! $vm instanceof TaskVm || $vm->state === TaskVmState::Destroyed
            || ($vm->node_id !== null && Instance::query()->where('node_id', $vm->node_id)->exists())) {
            return;
        }

        try {
            $vm->update(['state' => TaskVmState::Destroying, 'error_code' => null, 'error_message' => null]);
            $runtime->release($vm);
            $provider->destroy($vm);
            $node = $vm->node;
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

    private function gateway(): Node
    {
        return Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
            ->first() ?? throw new TaskVmException('task_vm.job_failed', 'No active Gateway Node can remove the task VM Node.', 500);
    }
}
