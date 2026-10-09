<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class CreateInstanceData
{
    public function __construct(
        public int $projectId,
        public int $nodeId,
        public string $name,
        public mixed $appOverrides,
        public ?string $domain,
        public ?string $branch,
        public ?string $databaseServer = null,
    ) {}
}
