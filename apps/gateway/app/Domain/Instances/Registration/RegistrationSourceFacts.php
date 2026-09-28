<?php

declare(strict_types=1);

namespace App\Domain\Instances\Registration;

use App\Domain\Instances\InstanceSourceLayout;

final readonly class RegistrationSourceFacts
{
    /** @param list<string> $worktreePaths */
    public function __construct(
        public string $path,
        public InstanceSourceLayout $layout,
        public string $repositoryUrl,
        public string $repositoryIdentity,
        public ?string $branch,
        public bool $detached,
        public string $commit,
        public ?string $defaultBranch,
        public string $inferredSlug,
        public ?string $inferredRoot,
        public string $commonRepositoryPath,
        public array $worktreePaths,
        public string $sourceDigest,
    ) {}
}
