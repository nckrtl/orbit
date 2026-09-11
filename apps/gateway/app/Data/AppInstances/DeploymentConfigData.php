<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentConfig;
use Spatie\LaravelData\Data;

final class DeploymentConfigData extends Data
{
    /** @param list<DeploymentStepData> $steps */
    public function __construct(
        public string $branch,
        public array $steps,
    ) {}

    public static function fromDomain(DeploymentConfig $config): self
    {
        return new self(
            $config->branch,
            array_map(DeploymentStepData::fromDomain(...), $config->steps),
        );
    }
}
