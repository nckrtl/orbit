<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\TaskCompute;

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
        public ProjectSourceAccess $sourceAccess = ProjectSourceAccess::GitHubApp,
        public bool $taskWorkspaceRoutedProvided = false,
        public bool $taskWorkspaceRouted = true,
        public TaskCompute $taskCompute = TaskCompute::Shared,
    ) {}

    /**
     * The task check to store. An omitted command is null for every Project type.
     */
    public function resolvedTaskCheck(): ?string
    {
        return $this->taskCheckProvided ? $this->taskCheck : null;
    }

    /**
     * Routing for task workspaces created later. An omitted value defaults to routed.
     */
    public function resolvedTaskWorkspaceRouted(): bool
    {
        return $this->taskWorkspaceRoutedProvided ? $this->taskWorkspaceRouted : true;
    }
}
