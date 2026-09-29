<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

final readonly class DeploymentFailure
{
    public function __construct(
        public DeploymentFailureBoundary $boundary,
        public string $errorCode,
    ) {}
}
