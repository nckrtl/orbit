<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class CreateInstanceData
{
    public function __construct(
        public int $appId,
        public int $nodeId,
        public string $name,
        public ?string $environment,
        public string $documentRoot,
        public string $phpVersion,
        public ?string $hostname,
    ) {}
}
