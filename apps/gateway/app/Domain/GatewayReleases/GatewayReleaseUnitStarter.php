<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * Starts the release unit for one queued release record and returns once systemd runs it. The
 * release itself runs in that unit, never in the PHP-FPM worker that queued it.
 */
interface GatewayReleaseUnitStarter
{
    /**
     * @param  \Closure(): bool  $claimed  whether the unit already claimed the record, for a release that ends at once
     *
     * @throws GatewayReleaseException when systemd refuses the unit, or the unit does not run
     */
    public function start(int $record, \Closure $claimed): void;

    /** Whether the unit of this record is starting or running. */
    public function isActive(int $record): bool;
}
