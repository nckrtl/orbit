<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class AppInspectionData
{
    public function __construct(
        public int $checkoutCount,
        public bool $repositoryOriginsMatch,
    ) {}
}
