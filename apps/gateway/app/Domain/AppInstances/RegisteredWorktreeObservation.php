<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

final readonly class RegisteredWorktreeObservation
{
    public function __construct(
        public string $checkoutPath,
        public ?string $branch,
        public string $startingCommit,
        public string $sourceIdentity,
    ) {}
}
