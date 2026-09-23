<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskCommentType: string
{
    case ReadyForReview = 'ready_for_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Blocked = 'blocked';
    case AssistanceRequested = 'assistance_requested';
    case Resolution = 'resolution';
}
