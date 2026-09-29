<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final readonly class DevelopmentSourceResolution
{
    public function __construct(
        public string $branch,
        public string $startingCommit,
    ) {}
}
