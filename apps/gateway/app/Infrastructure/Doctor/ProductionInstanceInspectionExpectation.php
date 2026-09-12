<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfiguration;

final readonly class ProductionInstanceInspectionExpectation
{
    public function __construct(
        public string $user,
        public string $home,
        public string $root,
        #[\SensitiveParameter]
        private string $environment,
        public string $caddy,
        public bool $associationMatches,
        public ?ProductionPhpRuntimeIdentity $runtime,
        public ?ProductionPhpRuntimeConfiguration $runtimeConfiguration,
    ) {}

    public function environment(): string
    {
        return $this->environment;
    }

    /** @return array{environment: string} */
    public function __debugInfo(): array
    {
        return ['environment' => '[PROTECTED]'];
    }
}
