<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class NodeInspectionData
{
    public function __construct(
        public bool $reachable,
        public ?string $platform,
        public ?string $architecture,
        public ?bool $wireGuardAddressMatches,
        public ?bool $agentBinaryExists = null,
        public ?bool $agentUnitExists = null,
        public ?bool $agentActive = null,
        public ?bool $agentChecksumMatches = null,
    ) {}
}
