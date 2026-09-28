<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * The model-free group values used to render an agent prompt.
 */
final readonly class TaskPromptGroup
{
    public function __construct(
        public int $id,
        public string $title,
        public string $brief,
        public string $projectSlug,
        public int $projectId,
        public ?string $defaultBranch,
        public ?string $taskCheck,
        public ?string $startCommit,
    ) {}
}
