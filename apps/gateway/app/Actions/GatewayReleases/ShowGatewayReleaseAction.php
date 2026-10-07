<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;

final readonly class ShowGatewayReleaseAction
{
    /** @return array<string, mixed> */
    public function execute(string $release): array
    {
        $id = GatewayReleaseCommit::assertId($release);
        $row = GatewayRelease::query()->where('release_id', $id)->latest('id')->first();

        if (! $row instanceof GatewayRelease) {
            throw new GatewayReleaseException(
                step: 'show',
                errorCode: 'gateway.release_not_found',
                message: "No release record exists for [{$id}].",
                status: 404,
            );
        }

        return $row->payload();
    }
}
