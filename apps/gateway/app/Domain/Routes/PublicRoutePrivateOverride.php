<?php

declare(strict_types=1);

namespace App\Domain\Routes;

final readonly class PublicRoutePrivateOverride
{
    public function __construct(
        public string $domain,
        public string $routerAddress,
        public string $workloadAddress,
    ) {}
}
