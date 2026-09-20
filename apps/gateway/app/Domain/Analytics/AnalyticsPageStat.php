<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** One path in a top-pages breakdown. */
final readonly class AnalyticsPageStat
{
    public function __construct(
        public string $path,
        public int $visitors,
    ) {}

    /** @return array{path: string, visitors: int} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'visitors' => $this->visitors,
        ];
    }
}
