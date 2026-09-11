<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use Closure;

final readonly class DeploymentCancellation
{
    /** @param Closure(): bool $cancelled */
    public function __construct(
        private Closure $cancelled,
    ) {}

    public static function never(): self
    {
        return new self(static fn (): bool => false);
    }

    public function requested(): bool
    {
        return ($this->cancelled)();
    }
}
