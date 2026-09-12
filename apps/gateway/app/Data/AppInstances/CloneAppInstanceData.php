<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

final readonly class CloneAppInstanceData
{
    public function __construct(
        public int $nodeId,
        public string $name,
        public string $previewName,
        public ?string $branch,
        public ?string $sqliteSourcePath,
    ) {}
}
