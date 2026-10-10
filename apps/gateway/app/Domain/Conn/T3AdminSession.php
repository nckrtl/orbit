<?php

declare(strict_types=1);

namespace App\Domain\Conn;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class T3AdminSession
{
    /** @param list<string> $scopes */
    public function __construct(
        #[SensitiveParameter] public string $token,
        public CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}
}
