<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\PreparedGatewayRelease;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;

final readonly class PrepareGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseBuilder $builder,
    ) {}

    public function execute(string $commit): PreparedGatewayRelease
    {
        return $this->lock->run(fn (): PreparedGatewayRelease => $this->builder->prepare($commit));
    }
}
