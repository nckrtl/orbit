<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

final readonly class DeploymentRelease
{
    public function __construct(
        public string $name,
        public string $path,
        public string $commit,
    ) {}
}
