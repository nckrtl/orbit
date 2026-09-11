<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

final readonly class DeploymentResultEvent extends DeploymentEvent
{
    public function __construct(
        int $sequence,
        string $requestId,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $selectedRelease,
    ) {
        parent::__construct($sequence, $requestId);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
