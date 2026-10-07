<?php

declare(strict_types=1);

namespace App\Data\GatewayReleases;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The state of automatic Gateway releases: the switch, a pause, the release that is current, the
 * last runner tick, since when a transient cause has kept releases from making progress, and the
 * branch head the runner last read with since when it differs from the deployed commit.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayReleaseAutomationData extends Data
{
    public function __construct(
        public bool $enabled,
        public bool $paused,
        public ?GatewayReleasePauseData $pause,
        public ?string $currentRelease,
        public ?string $currentSha,
        public ?GatewayReleaseTickData $lastTick,
        public ?string $stalledSince,
        public ?string $branchHead,
        public ?string $behindSince,
        public string $branch,
        public string $check,
    ) {}
}
