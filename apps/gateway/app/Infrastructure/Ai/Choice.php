<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use InvalidArgumentException;

final readonly class Choice
{
    /**
     * @param  array<string, string|null>  $options
     */
    public function __construct(
        public string $instructions,
        public array $options,
    ) {
        if (count($options) < 2) {
            throw new InvalidArgumentException('A choice question requires at least two options.');
        }
    }
}
