<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use DomainException;

final class TaskSequenceException extends DomainException
{
    public static function siblingRunning(int $groupId, int $runningTaskId): self
    {
        return new self("Task group {$groupId} already has a running subtask (task {$runningTaskId}).");
    }

    public static function notNext(int $taskId, int $groupId): self
    {
        return new self("Task {$taskId} is not the next pending subtask in group {$groupId}.");
    }
}
