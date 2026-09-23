<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\ProjectNodeExclusion;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DevelopmentNodeExclusionData extends Data
{
    public function __construct(
        public int $projectId,
        public string $projectSlug,
        public int $nodeId,
        public string $nodeName,
        public int $developmentInstanceCount,
    ) {}

    public static function fromModel(ProjectNodeExclusion $exclusion): self
    {
        $exclusion->loadMissing(['app', 'node']);

        return new self(
            projectId: $exclusion->app_id,
            projectSlug: $exclusion->app->slug,
            nodeId: $exclusion->node_id,
            nodeName: $exclusion->node->name,
            developmentInstanceCount: $exclusion->developmentInstanceCount(),
        );
    }
}
