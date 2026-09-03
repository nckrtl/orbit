<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

final readonly class DevelopmentSourceResolution
{
    public function __construct(
        public string $branch,
        public string $startingCommit,
    ) {}
}
