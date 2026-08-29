<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use Stringable;

/** One feature-proof attempt. Two attempts of one issue never share a resource identity. */
final readonly class AttemptId implements Stringable
{
    public function __construct(
        public string $value,
    ) {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The attempt ID is invalid.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** The readable prefix used inside resource names; the exact value stays the identity. */
    public function short(): string
    {
        return substr($this->value, 0, 8);
    }
}
