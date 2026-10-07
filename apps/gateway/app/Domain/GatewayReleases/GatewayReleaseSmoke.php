<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The smoke test that runs after the web app has switched. A Gateway with no smoke command yet
 * reports `skipped`. A failure throws with step `smoke`, which switches the release back when no
 * migrations ran.
 *
 * @phpstan-type SmokeResult array{outcome: string, output: string|null}
 */
interface GatewayReleaseSmoke
{
    /**
     * @return SmokeResult
     */
    public function run(string $id, string $sha): array;
}
