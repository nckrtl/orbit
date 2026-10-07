<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;

/**
 * Converts the in-place Gateway checkout into the release layout under the release lock
 * ([Adopt the release layout](/reference/gateway-recovery#adopt-the-release-layout)).
 */
final readonly class AdoptGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseAdopter $adopter,
    ) {}

    /** @return array<string, mixed> */
    public function execute(): array
    {
        return $this->lock->run(fn (): array => $this->adopter->adopt());
    }
}
