<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class RoleInspectionData
{
    public function __construct(
        public bool $packagesPresent,
        public bool $servicesActive,
        public bool $firewallProjectionMatches,
        /** The release `caddy version` reports, or null when the role needs no Caddy or none is installed. */
        public ?string $caddyVersion = null,
        /** Whether the Gateway machine routes the private domain to VPN DNS, or null when not checked. */
        public ?bool $privateDnsRouteMatches = null,
    ) {}
}
