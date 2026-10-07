<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseSmoke;

/** Smoke runs after the web switch. This binding reports that no smoke command is installed yet. */
final class NoGatewayReleaseSmoke implements GatewayReleaseSmoke
{
    public function run(string $id, string $sha): array
    {
        return ['outcome' => 'skipped', 'output' => null];
    }
}
