<?php

declare(strict_types=1);

namespace App\Domain\Instances\Apps;

use InvalidArgumentException;

final readonly class AppProjectionStepPlan
{
    /** @param array<string, string> $targets Exact resource identities and protected snapshot references, never file bytes. */
    public function __construct(
        public string $key,
        public int $sequence,
        public string $phase,
        public ?string $app,
        public string $resource,
        public string $action,
        public array $targets,
        public string $recoveryAction,
    ) {
        if ($sequence < 1 || ! in_array($phase, ['prepare', 'restore', 'cleanup'], true)) {
            throw new InvalidArgumentException('An app projection step requires a positive sequence and a known phase.');
        }
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return ['phase' => $this->phase, 'app' => $this->app, 'resource' => $this->resource, 'action' => $this->action, 'targets' => $this->targets, 'recovery_action' => $this->recoveryAction];
    }
}
