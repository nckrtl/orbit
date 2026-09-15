<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use InvalidArgumentException;

final readonly class InstanceDependencyUpdateResult
{
    public function __construct(
        public int $instanceId,
        public DependencyUpdateStepResult $composer,
        public DependencyUpdateStepResult $javascript,
        /** Null when rejected before package work and inventory refresh. */
        public ?InstanceDependencyScanResult $inventory,
        /** Preflight refusal or operation failure, separate from package and scan outcomes. */
        public ?string $errorCode = null,
    ) {
        if ($composer->ecosystem !== DependencyEcosystem::Composer || $javascript->ecosystem !== DependencyEcosystem::Npm) {
            throw new InvalidArgumentException('Instance updates require Composer and JavaScript outcomes in their named slots.');
        }

        if ($inventory !== null && $inventory->instanceId !== $instanceId) {
            throw new InvalidArgumentException('Post-update inventory must belong to the updated instance.');
        }
    }

    public function succeeded(): bool
    {
        return $this->errorCode === null
            && $this->composer->completed()
            && $this->javascript->completed()
            && $this->inventory?->succeeded() === true;
    }

    public function mayHaveMutated(): bool
    {
        return $this->composer->mayHaveMutated || $this->javascript->mayHaveMutated;
    }
}
