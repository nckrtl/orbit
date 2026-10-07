<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use SensitiveParameter;

/** A resolved sandbox endpoint, with no fallback to shared Node credentials. */
final readonly class PiEndpoint
{
    public function __construct(public string $url, #[SensitiveParameter] private string $credential) {}

    public function token(): string
    {
        return $this->credential;
    }
}
