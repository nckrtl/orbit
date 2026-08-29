<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class RoleInspectionData
{
    public function __construct(
        public bool $packagesPresent,
        public bool $servicesActive,
        public bool $firewallProjectionMatches,
    ) {}
}
