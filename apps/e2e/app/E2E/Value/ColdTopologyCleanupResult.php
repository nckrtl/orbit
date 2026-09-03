<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class ColdTopologyCleanupResult
{
    /**
     * @param list<string> $removed
     * @param list<string> $absent
     * @param list<string> $refused
     */
    public function __construct(
        public array $removed,
        public array $absent,
        public array $refused,
    ) {}

    public function successful(): bool
    {
        return $this->refused === [];
    }
}
