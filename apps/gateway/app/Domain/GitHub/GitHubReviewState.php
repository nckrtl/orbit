<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

enum GitHubReviewState: string
{
    case Pending = 'PENDING';
    case Commented = 'COMMENTED';
    case Approved = 'APPROVED';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Dismissed = 'DISMISSED';
}
