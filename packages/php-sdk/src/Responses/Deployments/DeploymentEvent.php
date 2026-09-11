<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

abstract readonly class DeploymentEvent
{
    public function __construct(
        public int $sequence,
        public string $requestId,
    ) {}
}
