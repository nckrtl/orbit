<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class TransferInstanceData
{
    public function __construct(
        public int $nodeId,
        public ?string $name,
        public ?string $sqliteSourcePath,
    ) {}
}
