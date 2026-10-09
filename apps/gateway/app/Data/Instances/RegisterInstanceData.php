<?php

declare(strict_types=1);

namespace App\Data\Instances;

final readonly class RegisterInstanceData
{
    public function __construct(
        public string $sourcePath,
        public bool $includeWorktrees,
        public ?int $projectId,
        public ?string $instanceName,
        public mixed $appOverrides,
        public ?string $domain,
        public bool $runSetup = false,
    ) {}
}
