<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskTurnOutcome: string
{
    case ReadyForReview = 'ready_for_review';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Blocked = 'blocked';
    case Answered = 'answered';

    public function fits(TaskThreadRole $role): bool
    {
        return match ($this) {
            self::ReadyForReview => $role === TaskThreadRole::Implementer,
            self::Approved, self::ChangesRequested, self::Answered => $role === TaskThreadRole::Reviewer,
            self::Blocked => true,
        };
    }

    public function commentType(): TaskCommentType
    {
        return TaskCommentType::from($this->value);
    }
}
