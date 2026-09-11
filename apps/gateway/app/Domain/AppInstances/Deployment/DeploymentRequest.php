<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use Closure;

final readonly class DeploymentRequest
{
    public DeploymentCancellation $cancellation;

    /** @param (Closure(DeploymentEvent): void)|null $output */
    public function __construct(
        private ?Closure $output = null,
        ?DeploymentCancellation $cancellation = null,
    ) {
        $this->cancellation = $cancellation ?? DeploymentCancellation::never();
    }

    public static function withoutOutput(): self
    {
        return new self(cancellation: DeploymentCancellation::never());
    }

    public function emit(DeploymentEvent $event): void
    {
        if ($this->output !== null) {
            ($this->output)($event);
        }
    }
}
