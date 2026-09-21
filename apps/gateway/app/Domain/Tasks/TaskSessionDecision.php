<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskSessionDecision
{
    public function __construct(
        public TaskSessionNextAction $action,
        public float $confidence,
        public string $reason,
    ) {}

    public static function escalate(string $reason, float $confidence = 0.0): self
    {
        return new self(TaskSessionNextAction::EscalateCoder, $confidence, $reason);
    }
}
