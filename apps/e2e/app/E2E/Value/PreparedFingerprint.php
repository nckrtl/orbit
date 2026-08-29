<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class PreparedFingerprint implements \Stringable
{
    public function __construct(
        public string $value,
        public array $manifest = [],
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The prepared fingerprint is invalid.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
