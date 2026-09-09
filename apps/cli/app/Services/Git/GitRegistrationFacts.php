<?php

declare(strict_types=1);

namespace App\Services\Git;

final readonly class GitRegistrationFacts
{
    /** @mago-expect lint:excessive-parameter-list The DTO carries the complete bounded set of discovered Git registration facts. */
    public function __construct(
        public string $path,
        public string $repositoryUrl,
        public string $slug,
        public ?string $defaultBranch,
        public ?string $branch,
        public ?string $root,
        public string $layout,
        public string $commit,
    ) {}
}
