<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class IncusSnapshot
{
    public function __construct(
        public IncusInstance $instance,
        public string $name,
    ) {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid Incus snapshot identity.');
        }
    }
}
