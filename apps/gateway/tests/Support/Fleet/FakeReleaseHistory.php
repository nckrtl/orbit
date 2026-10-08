<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\ReleaseHistory;

final readonly class FakeReleaseHistory implements ReleaseHistory
{
    /**
     * @param  list<string>  $ancestors  What every commit reaches, newest first.
     * @param  array<string, int>  $counts  A count per commit, instead of `$count`.
     */
    public function __construct(private int $count = 4681, private array $ancestors = [], private array $counts = []) {}

    public function commit(string $revision): ?string
    {
        return strlen($revision) === 40 ? $revision : null;
    }

    public function count(string $commit): ?int
    {
        return $this->counts[$commit] ?? $this->count;
    }

    public function ancestors(string $commit, int $limit): array
    {
        return array_slice(array_values(array_diff($this->ancestors, [$commit])), 0, $limit);
    }
}
