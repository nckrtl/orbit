<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

final readonly class DeploymentReleaseState
{
    /** @param list<string> $releases */
    public function __construct(
        public array $releases,
        public ?string $selectedRelease,
    ) {}
}
