<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Domain\Projects\ProjectType;
use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppData extends Data
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

    public static function fromModel(Project $app): self
    {
        return new self(
            id: $app->id,
            name: $app->name,
            slug: $app->slug,
            code: $app->code,
            type: $app->type,
            repositoryUrl: $app->repository_url,
            defaultBranch: $app->default_branch,
            root: $app->root,
            taskCheck: $app->taskCheckCommand(),
        );
    }
}
