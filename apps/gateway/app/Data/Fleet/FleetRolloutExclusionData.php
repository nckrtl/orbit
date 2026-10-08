<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A Node the fleet rollout leaves out, and why. */
#[MapOutputName(SnakeCaseMapper::class)]
final class FleetRolloutExclusionData extends Data
{
    public function __construct(
        public int $nodeId,
        public string $node,
        /** `sandbox`, `inactive`, `platform`, `unmanaged`, `gateway`, `roleless`, or `foreign_cli`. */
        public string $reason,
    ) {}
}
