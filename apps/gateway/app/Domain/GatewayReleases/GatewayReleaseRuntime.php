<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * How a switched release takes over the Gateway, in two phases around verify:
 *
 * - `handoff`: what serves requests. Caddy and PHP-FPM only when their rendered output changed, the OPcache reset,
 *   and the units with agent-view. It runs right after the switch, so verify sees the release as it will serve.
 * - `schedule`: the scheduler drain and restart, then document-cleanup reconcile and resume. It runs after verify,
 *   because the drain can wait for a long scheduled command.
 *
 * Switch-back runs the phases that already ran, for the release it returns to.
 *
 * @phpstan-type HandoffResult array<string, mixed>
 */
interface GatewayReleaseRuntime
{
    /**
     * @return HandoffResult
     *
     * @throws GatewayReleaseException
     */
    public function handoff(string $id): array;

    /**
     * @return HandoffResult with `cleanup_paused` as a bool
     *
     * @throws GatewayReleaseException
     */
    public function schedule(string $id): array;
}
