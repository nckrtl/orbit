<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * The model-free subtask values used to render an agent prompt.
 *
 * Position is included because it is part of the offline input contract, even though the current
 * prompt does not display it.
 */
final readonly class TaskPromptSubtask
{
    /** @param list<TaskDeliverable> $deliverables */
    public function __construct(
        public int $id,
        public string $title,
        public string $brief,
        public int $position,
        public array $deliverables,
    ) {}
}
