<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

enum GitHubPullRequestState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';
}
