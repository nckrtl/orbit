<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Projects\ProjectType;

final readonly class CreateProjectData
{
    public function __construct(
        public string $name,
        public string $slug,
        public ProjectType $type,
        public string $repositoryUrl,
        public ?string $defaultBranch,
        public string $root,
        public ?string $code = null,
        public bool $taskCheckProvided = false,
        public ?string $taskCheck = null,
    ) {}

    /**
     * The task check to store. An omitted command is null for every Project type.
     */
    public function resolvedTaskCheck(): ?string
    {
        return $this->taskCheckProvided ? $this->taskCheck : null;
    }
}
