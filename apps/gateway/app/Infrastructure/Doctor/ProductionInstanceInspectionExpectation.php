<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfiguration;

/**
 * What Doctor expects of a production Instance. `caddySites` holds the Instance's own site blocks exactly as a
 * Node Caddy build renders them into the live Caddyfile. Drift elsewhere in that file is `role.caddy_build_drift`.
 */
final readonly class ProductionInstanceInspectionExpectation
{
    public function __construct(
        public string $user,
        public string $home,
        public string $root,
        #[\SensitiveParameter]
        private string $environment,
        /** @var list<string> */
        public array $caddySites,
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
