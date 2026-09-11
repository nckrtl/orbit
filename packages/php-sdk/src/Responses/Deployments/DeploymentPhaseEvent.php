<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

final readonly class DeploymentPhaseEvent extends DeploymentEvent
{
    public function __construct(
        int $sequence,
        string $requestId,
        public string $phase,
        public ?string $stepName,
    ) {
        parent::__construct($sequence, $requestId);
    }
}
