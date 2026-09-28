<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

final readonly class InstanceSourceRevalidationExpectation
{
    /**
     * @param  list<string>  $requiredLinkedWorktreePaths
     * @param  list<string>  $permittedLinkedWorktreePaths
     * @param  array<int, InstanceSourceRevalidationState>  $authenticatedMemberStates
     */
    public function __construct(
        public array $requiredLinkedWorktreePaths,
        public array $permittedLinkedWorktreePaths,
        public array $authenticatedMemberStates = [],
    ) {}
}
