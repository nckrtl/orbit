<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use InvalidArgumentException;

final readonly class DeploymentStep
{
    public const int DefaultTimeoutSeconds = 300;

    public function __construct(
        public string $name,
        public DeploymentPhase $phase,
        #[\SensitiveParameter]
        public string $command,
        public int $timeoutSeconds = self::DefaultTimeoutSeconds,
    ) {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name) !== 1) {
            throw new InvalidArgumentException('The deployment step name is invalid.');
        }

        if ($command === '' || strlen($command) > 16 * 1024 || str_contains($command, "\0")) {
            throw new InvalidArgumentException('The deployment step command is invalid.');
        }

        if (preg_match('//u', $command) !== 1) {
            throw new InvalidArgumentException('The deployment step command is invalid.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 900) {
            throw new InvalidArgumentException('The deployment step timeout is invalid.');
        }
    }

    /** @return array{name: string, phase: string, command: string, timeout_seconds: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phase' => $this->phase->value,
            'command' => $this->command,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
