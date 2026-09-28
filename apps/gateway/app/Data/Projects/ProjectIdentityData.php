<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Projects\ProjectType;
use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The identity of a related Project, so a client can name it without a second request. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ProjectIdentityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ProjectType $type,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(id: $project->id, name: $project->name, slug: $project->slug, type: $project->type);
    }
}
