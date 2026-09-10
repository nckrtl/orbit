<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

final readonly class AppInstanceSourceInventory
{
    /**
     * @param  list<string>  $linkedWorktreePaths
     */
    public function __construct(
        public int $appInstanceId,
        public string $layout,
        public string $repositoryIdentity,
        public string $checkoutPath,
        public string $root,
        public ?string $branch,
        public string $startingCommit,
        public string $commonRepositoryPath,
        public string $sourceIdentity,
        public array $linkedWorktreePaths,
        public string $digest,
        public string $origin = '',
        public string $worktreeInventory = '',
    ) {}
}
