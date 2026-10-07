<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\TaskCompute;
use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ProjectData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $code,
        public ProjectType $type,
        public string $repositoryUrl,
        public ProjectSourceAccess $sourceAccess,
        public ?string $defaultBranch,
        public ?string $root,
        public ?string $taskCheck,
        public bool $taskWorkspaceRouted,
        public TaskCompute $taskCompute = TaskCompute::Shared,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(
            id: $project->id,
            name: $project->name,
            slug: $project->slug,
            code: $project->code,
            type: $project->type,
            repositoryUrl: $project->repository_url,
            sourceAccess: $project->source_access,
            defaultBranch: $project->default_branch,
            root: $project->root,
            taskCheck: $project->taskCheckCommand(),
            taskWorkspaceRouted: $project->task_workspace_routed,
            taskCompute: $project->task_compute,
        );
    }
}
