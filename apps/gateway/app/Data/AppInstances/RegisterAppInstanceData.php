<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

final readonly class RegisterAppInstanceData
{
    public function __construct(
        public string $sourcePath,
        public bool $includeWorktrees,
        public ?int $appId,
        public ?string $appName,
        public ?string $appSlug,
        public ?string $defaultBranch,
        public ?string $instanceName,
        public ?string $root,
        public ?string $hostname,
    ) {}
}
