<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
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

    public const string FailedReasonPrefix = 'Task VM failed: ';

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    /** A job that outlives its timeout fails the row instead of running again after `retry_after`. */
    public bool $failOnTimeout = true;

    public function __construct(public int $taskVmId)
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }

    /** The row becomes `failed`, and its group asks for assistance, so the failure shows in `tasks:status`. */
    public function failed(Throwable $exception): void
    {
        $code = TaskVmException::codeOf($exception);
        $message = mb_substr($exception->getMessage(), 0, 2000);
        $failed = TaskVm::query()->whereKey($this->taskVmId)->where('state', TaskVmState::Provisioning)->update([
            'state' => TaskVmState::Failed,
            'error_code' => $code,
            'error_message' => $message,
        ]);
        $group = TaskVm::query()->find($this->taskVmId)?->group;
        if ($failed === 1 && $group instanceof Task) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, self::FailedReasonPrefix."{$code}: {$message} Cancel the group to destroy the VM.", replaceFailure: true);
        }
    }

    /** The row while it is still `provisioning`, else null. */
    private function provisioning(): ?TaskVm
    {
        $vm = TaskVm::query()->find($this->taskVmId);

        return $vm?->state === TaskVmState::Provisioning ? $vm : null;
    }
}
