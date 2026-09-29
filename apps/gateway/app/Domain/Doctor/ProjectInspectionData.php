<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class ProjectInspectionData
{
    public function __construct(
        public int $checkoutCount,
        public bool $repositoryOriginsMatch,
        /** @var list<int> */
        public array $mismatchingInstanceIds = [],
        /** @var list<int> */
        public array $failedInstanceIds = [],
    ) {}
}
