<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class InstanceInspectionData
{
    public function __construct(
        public bool $checkoutExists,
        public bool $repositoryLayoutMatches,
        public bool $originMatches,
        public bool $sourceIdentityMatches,
    ) {}
}
