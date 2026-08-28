<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class GuestCommandResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
