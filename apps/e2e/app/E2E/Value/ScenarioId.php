<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use Stringable;

final readonly class ScenarioId implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-z][a-z0-9-]{0,62}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The scenario ID is invalid.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
