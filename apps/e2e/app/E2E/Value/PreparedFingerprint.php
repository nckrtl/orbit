<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class PreparedFingerprint implements \Stringable
{
    public function __construct(
        public string $value,
        public array $manifest = [],
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
