<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class GatewayVpnInspectionData
{
    public function __construct(
        public bool $interfaceActive,
        public bool $serverConfigMatches,
        public bool $dnsConfigMatches,
    ) {}
}
