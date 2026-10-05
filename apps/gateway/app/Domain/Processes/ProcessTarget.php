<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Instance;
use App\Models\Node;

final readonly class ProcessTarget
{
    public string $defaultWorkingDirectory;

    public function __construct(
        public Node $node,
        public string $user,
        public string $checkoutPath,
        public ?string $certificateScope = null,
        public ?Instance $instance = null,
        public string $environmentFile = '',
        public bool $productionReleaseLayout = false,
        public ?string $routeDomain = null,
        public bool $onDemandHostStart = false,
        public ?string $app = null,
    ) {
        $this->defaultWorkingDirectory = $checkoutPath;
    }

    public function port(string $kind): ?int
    {
        $value = $this->instance?->runtimeForApp($this->app)[$kind] ?? null;

        return is_int($value) ? $value : null;
    }

    public function usesAppIdentity(): bool
    {
        return $this->app !== null && ($this->instance?->usesAppRuntimeIdentity($this->app) ?? false);
    }
}
