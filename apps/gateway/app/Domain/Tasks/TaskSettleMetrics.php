<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskSettleMetrics
{
    public function __construct(
        public int $tokens,
        public int $lineDiff,
        public int $durationMs,
    ) {}
}
