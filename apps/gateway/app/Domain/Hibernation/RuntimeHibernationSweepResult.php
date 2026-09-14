<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

final readonly class RuntimeHibernationSweepResult
{
    public function __construct(
        public int $halted,
        public int $pruned,
    ) {}
}
