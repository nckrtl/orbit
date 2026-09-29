<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class CloneInstanceData
{
    public function __construct(
        public int $nodeId,
        public string $name,
        public string $previewName,
        public ?string $branch,
        public ?string $sqliteSourcePath,
    ) {}
}
