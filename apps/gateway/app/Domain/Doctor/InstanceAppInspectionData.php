<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class InstanceAppInspectionData
{
    public function __construct(public bool $pathMatches, public bool $sourceProfileMatches) {}
}
