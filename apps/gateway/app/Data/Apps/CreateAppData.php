<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Domain\Projects\ProjectType;

final readonly class CreateAppData
{
    /**
     * @param  array<array-key, mixed>|null  $defaults
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ProjectType $type,
        public string $repositoryUrl,
        public ?string $defaultBranch,
        public string $root,
        public ?array $defaults,
        public ?string $code = null,
    ) {}
}
