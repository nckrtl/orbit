<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final readonly class ProductionPhpRuntimeConfiguration
{
    public function __construct(
        public string $main,
        public string $pool,
        public string $localDefaults,
        public string $masterIni,
        public string $unit,
    ) {}
}
