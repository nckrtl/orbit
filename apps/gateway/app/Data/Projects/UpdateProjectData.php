<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\TaskCompute;

final readonly class UpdateProjectData
{
    public function __construct(
        public bool $typeProvided,
        public ?ProjectType $type,
        public bool $slugProvided,
        public ?string $slug,
        public bool $repositoryUrlProvided,
        public ?string $repositoryUrl,
        public bool $defaultBranchProvided,
        public ?string $defaultBranch,
        public bool $rootProvided,
        public ?string $root,
        public ?string $code = null,
        public bool $taskCheckProvided = false,
        public ?string $taskCheck = null,
        public bool $sourceAccessProvided = false,
        public ?ProjectSourceAccess $sourceAccess = null,
        public bool $taskWorkspaceRoutedProvided = false,
        public bool $taskWorkspaceRouted = true,
        public ?TaskCompute $taskCompute = null,
    ) {}

    public function hasChanges(): bool
    {
        return $this->code !== null
            || $this->typeProvided
            || $this->slugProvided
            || $this->repositoryUrlProvided
            || $this->defaultBranchProvided
            || $this->rootProvided
            || $this->taskCheckProvided
            || $this->sourceAccessProvided
            || $this->taskWorkspaceRoutedProvided
            || $this->taskCompute !== null;
    }

    public function hasReconcilableChanges(): bool
    {
        return $this->slugProvided
            || $this->repositoryUrlProvided
            || $this->defaultBranchProvided
            || $this->rootProvided;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'code' => $this->code,
            'type' => $this->typeProvided ? $this->type?->value : null,
            'slug' => $this->slugProvided ? $this->slug : null,
            'repository_url' => $this->repositoryUrlProvided ? $this->repositoryUrl : null,
            'default_branch' => $this->defaultBranchProvided ? $this->defaultBranch : null,
            'root' => $this->rootProvided ? $this->root : null,
            'task_check' => $this->taskCheckProvided ? $this->taskCheck : null,
            'source_access' => $this->sourceAccessProvided ? $this->sourceAccess?->value : null,
            'task_workspace_routed' => $this->taskWorkspaceRoutedProvided ? $this->taskWorkspaceRouted : null,
            'task_compute' => $this->taskCompute?->value,
            'provided' => [
                $this->typeProvided,
                $this->slugProvided,
                $this->repositoryUrlProvided,
                $this->defaultBranchProvided,
                $this->rootProvided,
                $this->taskCheckProvided,
                $this->sourceAccessProvided,
                $this->taskWorkspaceRoutedProvided,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
