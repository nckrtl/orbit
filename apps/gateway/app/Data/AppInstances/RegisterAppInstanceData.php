<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

/** @mago-expect lint:excessive-parameter-list The data object preserves the complete typed registration request. */
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
