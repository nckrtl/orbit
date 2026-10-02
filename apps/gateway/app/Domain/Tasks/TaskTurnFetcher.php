<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shares the turn fetch and its notice between scheduler turns and operator resolutions. */
final readonly class TaskTurnFetcher
{
    public function __construct(private TaskBaseBranchFetcher $bases, private TaskTurnFetchNotice $notice) {}

    /** An ordinary turn continues with a warning when the fetch fails. */
    public function beforeTurn(Task $group): void
    {
        try {
            $this->fetch($group);
        } catch (Throwable $exception) {
            Log::warning(TaskTurnFetchNotice::Failed.' The turn will continue.', [
                'task_group_id' => $group->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /** Resumed preparation needs the same fetch, but must retry a failure before starting. */
    public function fetch(Task $group): void
    {
        $this->notice->clear();
        try {
            $this->bases->fetchForTurn($group);
        } catch (Throwable $exception) {
            $this->notice->fail();

            throw $exception;
        }
    }
}
