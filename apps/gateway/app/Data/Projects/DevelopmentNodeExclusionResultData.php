<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\ProjectNodeExclusion;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DevelopmentNodeExclusionResultData extends Data
{
    public function __construct(
        public int $projectId,
        public string $projectSlug,
        public int $nodeId,
        public string $nodeName,
        public int $developmentInstanceCount,
        public bool $alreadyExists,
    ) {}

    public static function fromModel(ProjectNodeExclusion $exclusion, bool $alreadyExists): self
    {
        $entry = DevelopmentNodeExclusionData::fromModel($exclusion);

        return new self(
            projectId: $entry->projectId,
            projectSlug: $entry->projectSlug,
            nodeId: $entry->nodeId,
            nodeName: $entry->nodeName,
            developmentInstanceCount: $entry->developmentInstanceCount,
            alreadyExists: $alreadyExists,
        );
    }
}
