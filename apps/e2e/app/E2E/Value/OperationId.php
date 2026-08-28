<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class OperationId implements \Stringable
{
    public function __construct(
        public string $value,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The operation ID is invalid.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
