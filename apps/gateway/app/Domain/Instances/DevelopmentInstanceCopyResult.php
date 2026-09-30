<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final readonly class DevelopmentInstanceCopyResult
{
    public function __construct(
        public string $mode,
        public string $head,
    ) {}
}
