<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Infrastructure\GatewayReleases\GatewayReleaseRequests;
use App\Models\GatewayRelease;

/**
 * Queues a rollback to a retained release and starts its release unit. The target and the
 * migration guard are checked before anything is queued, and again under the release lock.
 */
final readonly class StartGatewayReleaseRollbackAction
{
    public function __construct(
        private RollbackGatewayReleaseAction $rollback,
        private GatewayReleaseRequests $requests,
    ) {}

    public function execute(string $release, bool $force): GatewayRelease
    {
        $sha = $this->rollback->check($release, $force);

        return $this->requests->start('rollback', $release, $sha, $force);
    }
}
