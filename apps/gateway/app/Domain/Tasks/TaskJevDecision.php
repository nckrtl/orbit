<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskJevDecision
{
    public function __construct(
        public TaskJevOutcome $outcome,
        public float $confidence,
        public string $reason,
    ) {}
}
