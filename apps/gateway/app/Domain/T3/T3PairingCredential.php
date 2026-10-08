<?php

declare(strict_types=1);

namespace App\Domain\T3;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class T3PairingCredential
{
    public function __construct(
        public string $id,
        #[SensitiveParameter] public string $credential,
        public CarbonImmutable $expiresAt,
    ) {}
}
