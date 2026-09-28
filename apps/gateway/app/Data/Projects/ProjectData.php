<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Projects\ProjectType;
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
        public ?string $defaultBranch,
        public ?string $root,
        public ?string $taskCheck = null,
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
            defaultBranch: $project->default_branch,
            root: $project->root,
            taskCheck: $project->taskCheckCommand(),
        );
    }
}
