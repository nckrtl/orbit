<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use DateTimeImmutable;

/**
 * The smoke test that runs after the web app has switched. A failure or a timeout throws with step
 * `smoke` and carries the smoke report in its details, which switches the release back when no
 * migrations ran and pauses it when they did.
 *
 * @phpstan-type SmokeResult array{outcome: string, report: array<array-key, mixed>|null}
 */
interface GatewayReleaseSmoke
{
    /**
     * @param  DateTimeImmutable|null  $since  When the runtime handoff started. The scheduler, agent view,
     *                                         and a tasks tick must have started after it.
     * @return SmokeResult
     *
     * @throws GatewayReleaseException
     */
    public function run(string $id, string $sha, ?DateTimeImmutable $since = null): array;
}
