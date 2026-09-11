<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

final readonly class DeploymentDeadline
{
    public const int InfrastructureSeconds = 900;

    public function __construct(
        public int $seconds,
    ) {}

    public static function for(DeploymentConfig $config): self
    {
        return new self(
            array_sum(array_map(
                static fn (DeploymentStep $step): int => $step->timeoutSeconds,
                $config->steps,
            )) + self::InfrastructureSeconds,
        );
    }
}
