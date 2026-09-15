<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class PrivateRouteProjectionObservation
{
    public function __construct(
        public ?bool $routingScopeMatches,
        public ?bool $routerCaddyMatches,
        public ?bool $workloadCaddyMatches,
        public ?bool $certificateMatches,
        public ?bool $dnsMatches,
        public ?bool $firewallMatches,
        public ?bool $laravelUrlMatches,
    ) {}
}
