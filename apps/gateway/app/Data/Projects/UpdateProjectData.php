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
        public ?string $code = null,
        public bool $taskCheckProvided = false,
        public ?string $taskCheck = null,
        public bool $sourceAccessProvided = false,
        public ?ProjectSourceAccess $sourceAccess = null,
        public bool $taskWorkspaceRoutedProvided = false,
        public bool $taskWorkspaceRouted = true,
        public ?TaskCompute $taskCompute = null,
        public bool $reviewAndMergeProvided = false,
        public bool $reviewAndMerge = false,
        public bool $mergeCheckProvided = false,
        public ?string $mergeCheck = null,
        public bool $appsProvided = false,
        public mixed $apps = null,
    ) {}

    public function hasChanges(): bool
    {
        return $this->code !== null
            || $this->typeProvided
            || $this->slugProvided
            || $this->repositoryUrlProvided
            || $this->defaultBranchProvided
            || $this->appsProvided
            || $this->taskCheckProvided
            || $this->sourceAccessProvided
            || $this->taskWorkspaceRoutedProvided
            || $this->taskCompute !== null
            || $this->reviewAndMergeProvided
            || $this->mergeCheckProvided;
    }

    /** Settings that change only the Project row, under the Instance operation lock. */
    public function hasTaskSettings(): bool
    {
        return $this->taskCheckProvided || $this->taskWorkspaceRoutedProvided || $this->taskCompute !== null
            || $this->reviewAndMergeProvided || $this->mergeCheckProvided;
    }

    public function hasReconcilableChanges(): bool
    {
        return $this->slugProvided
            || $this->repositoryUrlProvided
            || $this->defaultBranchProvided;
    }

    /**
     * Identifies a journaled source update. The app list never joins that journal, and the
     * retired `root` slot stays null so incomplete updates keep their recorded fingerprint.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'code' => $this->code,
            'type' => $this->typeProvided ? $this->type?->value : null,
            'slug' => $this->slugProvided ? $this->slug : null,
            'repository_url' => $this->repositoryUrlProvided ? $this->repositoryUrl : null,
            'default_branch' => $this->defaultBranchProvided ? $this->defaultBranch : null,
            'root' => null,
            'task_check' => $this->taskCheckProvided ? $this->taskCheck : null,
            'source_access' => $this->sourceAccessProvided ? $this->sourceAccess?->value : null,
            'task_workspace_routed' => $this->taskWorkspaceRoutedProvided ? $this->taskWorkspaceRouted : null,
            'task_compute' => $this->taskCompute?->value,
            'review_and_merge' => $this->reviewAndMergeProvided ? $this->reviewAndMerge : null,
            'merge_check' => $this->mergeCheckProvided ? $this->mergeCheck : null,
            'provided' => [
                $this->typeProvided,
                $this->slugProvided,
                $this->repositoryUrlProvided,
                $this->defaultBranchProvided,
                false,
                $this->taskCheckProvided,
                $this->sourceAccessProvided,
                $this->taskWorkspaceRoutedProvided,
                $this->reviewAndMergeProvided,
                $this->mergeCheckProvided,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
