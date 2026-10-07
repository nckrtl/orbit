<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Models\GatewayRelease;

final readonly class ListGatewayReleasesAction
{
    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        return array_values(GatewayRelease::query()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (GatewayRelease $release): array => $release->payload())
            ->all());
    }
}
