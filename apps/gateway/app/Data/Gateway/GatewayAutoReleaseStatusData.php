<?php

declare(strict_types=1);

namespace App\Data\Gateway;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Automatic releases in Gateway status: the switch, a pause, and the runner's last tick. The full
 * state is `GET /api/v1/gateway/release-automation`.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class GatewayAutoReleaseStatusData extends Data
{
    public function __construct(
        public bool $enabled,
        public bool $paused,
        public ?string $lastCheckedAt,
        public ?string $lastResult,
    ) {}
}
