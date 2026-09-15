<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class PublicRouteEdgeObservation
{
    public function __construct(
        public ?bool $ingressProjectionMatches,
        public ?bool $privateForwardingMatches,
        public ?bool $publicTlsMatches,
        public ?bool $firewallMatches,
    ) {}
}
