<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencySnapshotData extends Data
{
    public function __construct(
        public string $observedAt,
        public DependencySourceData $source,
        public ?DependencyGraphData $graph,
    ) {}

    public static function fromDomain(DependencySnapshot $snapshot): self
    {
        return new self(
            $snapshot->observedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339),
            DependencySourceData::fromDomain($snapshot->source),
            $snapshot->graph === null ? null : DependencyGraphData::fromDomain($snapshot->graph),
        );
    }
}
