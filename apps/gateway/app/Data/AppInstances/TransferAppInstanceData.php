<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

final readonly class TransferAppInstanceData
{
    public function __construct(
        public int $nodeId,
        public ?string $name,
        public ?string $sqliteSourcePath,
    ) {}
}
