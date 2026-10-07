<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseRequests;
use App\Models\GatewayRelease;

/**
 * Queues a manual deploy of one commit and starts its release unit. It returns the queued record at
 * once; the caller follows the record until it finishes.
 */
final readonly class StartGatewayReleaseDeployAction
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseRequests $requests,
    ) {}

    public function execute(string $commit): GatewayRelease
    {
        $requested = GatewayReleaseCommit::parse($commit);

        if (! $this->layout->isAdopted()) {
            throw new GatewayReleaseException(
                step: 'deploy',
                errorCode: 'gateway.release_not_adopted',
                message: 'The Gateway is not running from a release. Run gateway:release:adopt first.',
            );
        }

        return $this->requests->start('deploy', $requested, GatewayReleaseCommit::isSha($requested) ? $requested : null);
    }
}
