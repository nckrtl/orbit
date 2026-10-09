<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The shared shape of the jobs that bring a task VM to `ready`: one job per row at a time on the
 * `task-vms` queue, a no-op once the row has left `provisioning`, and a `failed` row with the
 * error once the job has no retries left.
 */
trait ProvisionsTaskVm
{
    use Queueable;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    public function __construct(public int $taskVmId)
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }

    public function failed(Throwable $exception): void
    {
        TaskVm::query()->whereKey($this->taskVmId)->where('state', TaskVmState::Provisioning)->update([
            'state' => TaskVmState::Failed,
            'error_code' => TaskVmException::codeOf($exception),
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }

    /** The row while it is still `provisioning`, else null. */
    private function provisioning(): ?TaskVm
    {
        $vm = TaskVm::query()->find($this->taskVmId);

        return $vm?->state === TaskVmState::Provisioning ? $vm : null;
    }
}
