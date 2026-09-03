<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

final readonly class CreateAppInstanceData
{
    public function __construct(
        public int $appId,
        public int $nodeId,
        public string $name,
        public ?string $root,
    ) {}
}
