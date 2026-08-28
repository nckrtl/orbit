<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class GuestCommand
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public int $timeout = 60,
        public ?string $stdin = null,
    ) {
        if (
            $command === []
            || $timeout < 1
            || $stdin !== null
            && (strlen($stdin) > 1_048_576
            || str_contains($stdin, "\0")
            || ! mb_check_encoding($stdin, 'UTF-8'))
        ) {
            throw new InvalidArgumentException('Guest command and timeout must be valid.');
        }

        foreach ($command as $argument) {
            /** @mago-expect analysis:redundant-type-comparison Runtime callers can violate the PHPDoc list type. */
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Guest command arguments must be safe strings.');
            }
        }
    }
}
