<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskReviewCandidateResult
{
    public function __construct(public TaskReviewReadStatus $status, public ?TaskReviewCandidate $candidate = null) {}
}
