<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\ReleaseHistory;

final readonly class FakeReleaseHistory implements ReleaseHistory
{
    public function __construct(private int $count = 4681) {}

    public function commit(string $revision): ?string
    {
        return strlen($revision) === 40 ? $revision : null;
    }

    public function count(string $commit): ?int
    {
        return $this->count;
    }
}
