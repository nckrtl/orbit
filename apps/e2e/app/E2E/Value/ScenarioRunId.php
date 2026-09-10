<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use Stringable;

final readonly class ScenarioRunId implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The scenario run ID is invalid.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public function short(): string
    {
        return substr($this->value, 0, 8);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
