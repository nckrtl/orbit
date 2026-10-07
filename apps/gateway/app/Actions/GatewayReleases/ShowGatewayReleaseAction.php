<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;

/**
 * Finds one release record. A selector of one to six digits is a record id. A hex SHA of 7 to 40
 * characters, such as a 12-digit release id, selects the newest record of that commit.
 */
final readonly class ShowGatewayReleaseAction
{
    /** @return array<string, mixed> */
    public function execute(string $release): array
    {
        return $this->find($release)->payload();
    }

    public function find(string $release): GatewayRelease
    {
        $selector = strtolower(trim($release));

        if (preg_match('/\A[1-9][0-9]{0,5}\z/D', $selector) === 1) {
            $row = GatewayRelease::query()->find((int) $selector);
        } elseif (preg_match('/\A[0-9a-f]{7,40}\z/D', $selector) === 1) {
            $row = GatewayRelease::query()
                ->where(static fn ($query) => $query
                    ->where('sha', 'like', $selector.'%')
                    ->orWhere('requested', $selector))
                ->latest('id')
                ->first();
        } else {
            throw new GatewayReleaseException(
                step: 'show',
                errorCode: 'gateway.release_id_invalid',
                message: 'Name a release by its record id or by a hex SHA of 7 to 40 characters.',
                status: 422,
            );
        }

        if (! $row instanceof GatewayRelease) {
            throw new GatewayReleaseException(
                step: 'show',
                errorCode: 'gateway.release_not_found',
                message: "No release record exists for [{$selector}].",
                status: 404,
            );
        }

        return $row;
    }
}
