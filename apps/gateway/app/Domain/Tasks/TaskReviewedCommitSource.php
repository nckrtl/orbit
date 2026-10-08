<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** How Orbit came to review a commit (ADR 0203). */
enum TaskReviewedCommitSource: string
{
    /** Orbit's final review approved Orbit's own work, and Orbit pushed it. */
    case OrbitPush = 'orbit_push';

    /** Orbit's final review approved a pull request head that someone else pushed. */
    case PullRequestReview = 'pull_request_review';
}
