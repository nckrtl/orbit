<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

final readonly class ObservationGrant
{
    public function __construct(
        public string $observerUrl,
        public string $token,
        public ObservationGrantClaims $claims,
    ) {}
}
