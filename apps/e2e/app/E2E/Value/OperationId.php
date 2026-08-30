<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class OperationId implements \Stringable
{
    public function __construct(
        public string $value,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The operation ID is invalid.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
