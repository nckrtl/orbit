<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

final readonly class DependencyRequirement
{
    public function __construct(
        /** Null denotes the root manifest; otherwise this is a resolution ID. */
        public ?string $from,
        /** Null retains an unresolved requirement without inventing a resolution. */
        public ?string $to,
        /** Declared name, which can differ from the resolved package name for aliases or providers. */
        public string $name,
        public string $constraint,
        public DependencyRequirementKind $kind,
        /** Declaration scope, not the complete reachability of the target resolution. */
        public DependencyScope $scope,
        public bool $optional = false,
    ) {}
}
