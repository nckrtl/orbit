<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The fleet rollout as `fleet:rollout:status` shows it. */
#[MapOutputName(SnakeCaseMapper::class)]
final class FleetRolloutStatusData extends Data
{
    /** @param list<FleetRolloutExclusionData> $excluded */
    public function __construct(
        /** Whether `orbit-fleet-converge.service` runs rollouts: `ORBIT_FLEET_ROLLOUT`. */
        public bool $enabled,
        /** The newest rollout's status, or `none` before the first rollout. */
        public string $status,
        /** The commit the running Gateway wants the fleet on, or null when its version is not a known commit. */
        public ?string $desiredCommit,
        public ?FleetRolloutData $rollout,
        public array $excluded,
    ) {}
}
