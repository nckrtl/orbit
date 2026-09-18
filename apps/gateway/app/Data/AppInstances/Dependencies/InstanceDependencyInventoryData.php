<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceDependencyInventoryData extends Data
{
    public function __construct(
        public int $instanceId,
        public ?bool $succeeded,
        public DependencyInventoryData $composer,
        public DependencyInventoryData $javascript,
    ) {}

    public static function fromResults(int $instanceId, ?DependencyScanResult $composer, ?DependencyScanResult $javascript): self
    {
        return new self(
            $instanceId,
            $composer === null || $javascript === null ? null : $composer->succeeded() && $javascript->succeeded(),
            DependencyInventoryData::fromResult(DependencyEcosystem::Composer, $composer),
            DependencyInventoryData::fromResult(DependencyEcosystem::Npm, $javascript),
        );
    }
}
