<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

final readonly class AppInstanceSourceRevalidationExpectation
{
    /**
     * @param  list<string>  $requiredLinkedWorktreePaths
     * @param  list<string>  $permittedLinkedWorktreePaths
     * @param  array<int, AppInstanceSourceRevalidationState>  $authenticatedMemberStates
     */
    public function __construct(
        public array $requiredLinkedWorktreePaths,
        public array $permittedLinkedWorktreePaths,
        public array $authenticatedMemberStates = [],
    ) {}
}
