<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** The review decisions Orbit submits on an incoming pull request (ADR 0203). */
enum GitHubReviewEvent: string
{
    case Approve = 'APPROVE';
    case RequestChanges = 'REQUEST_CHANGES';
}
