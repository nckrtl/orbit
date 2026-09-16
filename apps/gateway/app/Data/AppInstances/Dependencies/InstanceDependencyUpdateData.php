<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\InstanceDependencyUpdateResult;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceDependencyUpdateData extends Data
{
    public function __construct(
        public int $instanceId,
        public bool $succeeded,
        public ?string $errorCode,
        public bool $mayHaveMutated,
        public DependencyUpdateStepData $composer,
        public DependencyUpdateStepData $javascript,
        public ?InstanceDependencyInventoryData $inventory,
    ) {}

    public static function fromResult(InstanceDependencyUpdateResult $result): self
    {
        $inventory = $result->inventory;

        return new self(
            $result->instanceId,
            $result->succeeded(),
            $result->errorCode,
            $result->mayHaveMutated(),
            DependencyUpdateStepData::fromResult($result->composer),
            DependencyUpdateStepData::fromResult($result->javascript),
            $inventory === null ? null : InstanceDependencyInventoryData::fromResults($inventory->instanceId, $inventory->composer, $inventory->javascript),
        );
    }
}
