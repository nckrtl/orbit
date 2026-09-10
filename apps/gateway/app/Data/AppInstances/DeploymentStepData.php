<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentStep;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DeploymentStepData extends Data
{
    public function __construct(
        public string $name,
        public string $phase,
        #[\SensitiveParameter]
        public string $command,
        public int $timeoutSeconds,
    ) {}

    public static function fromDomain(DeploymentStep $step): self
    {
        return new self($step->name, $step->phase->value, $step->command, $step->timeoutSeconds);
    }
}
