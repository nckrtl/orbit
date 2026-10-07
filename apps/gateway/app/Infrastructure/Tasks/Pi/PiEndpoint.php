<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use SensitiveParameter;

/** A resolved sandbox endpoint, with no fallback to shared Node credentials. */
final readonly class PiEndpoint
{
    /** @param list<string> $additionalSecrets */
    public function __construct(public string $url, #[SensitiveParameter] private string $credential, #[SensitiveParameter] private array $additionalSecrets = []) {}

    /** @return list<string> */
    public function secrets(): array
    {
        return array_values(array_filter([$this->credential, ...$this->additionalSecrets], static fn (string $value): bool => $value !== ''));
    }

    public function token(): string
    {
        return $this->credential;
    }
}
