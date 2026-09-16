<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class CustomProxyRouteObservation
{
    public function __construct(
        public ?bool $dnsMatches,
        public ?bool $certificateMatches,
        public ?bool $caddyMatches,
        public ?bool $upstreamReachable,
    ) {}
}
