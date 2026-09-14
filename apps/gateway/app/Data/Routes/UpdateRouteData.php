<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Domain\Routes\RoutePublication;

final readonly class UpdateRouteData
{
    public function __construct(
        public bool $domainProvided,
        public ?string $domain,
        public bool $publicationProvided,
        public ?RoutePublication $publication,
    ) {}
}
