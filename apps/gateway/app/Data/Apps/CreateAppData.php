<?php

declare(strict_types=1);

namespace App\Data\Apps;

final readonly class CreateAppData
{
    /**
     * @param array<array-key, mixed>|null $defaults
     *
     * @mago-expect lint:excessive-parameter-list The input carries the complete bounded App creation contract.
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $repositoryUrl,
        public ?string $mainBranch,
        public string $root,
        public ?array $defaults,
    ) {}
}
