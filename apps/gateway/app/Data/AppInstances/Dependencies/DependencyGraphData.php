<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyGraph;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencyGraphData extends Data
{
    /**
     * @param  list<DependencyResolutionData>  $resolutions
     * @param  list<DependencyRequirementData>  $requirements
     */
    public function __construct(public array $resolutions, public array $requirements) {}

    public static function fromDomain(DependencyGraph $graph): self
    {
        return new self(
            array_map(DependencyResolutionData::fromDomain(...), $graph->resolutions),
            array_map(DependencyRequirementData::fromDomain(...), $graph->requirements),
        );
    }
}
