<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * Installs the systemd units that run Gateway releases outside PHP-FPM and the scheduler: the
 * automatic release timer and service, and the template unit that runs a requested release.
 */
interface GatewayReleaseUnitConverger
{
    public function converge(): void;
}
