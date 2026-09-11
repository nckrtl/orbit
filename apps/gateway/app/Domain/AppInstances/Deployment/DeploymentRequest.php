<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use Closure;

final readonly class DeploymentRequest
{
    public DeploymentCancellation $cancellation;

    /**
     * @param  (Closure(DeploymentEvent): void)|null  $output
     * @param  (Closure(DeploymentProgressPhase, ?string): void)|null  $phase
     */
    public function __construct(
        private ?Closure $output = null,
        ?DeploymentCancellation $cancellation = null,
        private ?Closure $phase = null,
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

    public function emitPhase(DeploymentProgressPhase $phase, ?string $stepName = null): void
    {
        if ($this->phase !== null) {
            ($this->phase)($phase, $stepName);
        }
    }
}
