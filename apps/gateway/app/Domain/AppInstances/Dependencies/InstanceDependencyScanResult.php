<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use InvalidArgumentException;

final readonly class InstanceDependencyScanResult
{
    public function __construct(
        public int $instanceId,
        public DependencyScanResult $composer,
        public DependencyScanResult $javascript,
    ) {
        if ($composer->ecosystem !== DependencyEcosystem::Composer || $javascript->ecosystem !== DependencyEcosystem::Npm) {
            throw new InvalidArgumentException('Instance scans require Composer and JavaScript outcomes in their named slots.');
        }
    }

    public function succeeded(): bool
    {
        return $this->composer->succeeded() && $this->javascript->succeeded();
    }
}
