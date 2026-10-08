<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** The outcome of one Node's visit in a fleet rollout, with the evidence the record keeps. */
final readonly class FleetNodeResult
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public FleetNodeOutcome $outcome,
        public ?string $step = null,
        public ?string $errorCode = null,
        public ?string $message = null,
        public array $evidence = [],
        public ?string $cliVersion = null,
        public ?string $agentVersion = null,
        public ?string $footprintDigest = null,
    ) {}
}
