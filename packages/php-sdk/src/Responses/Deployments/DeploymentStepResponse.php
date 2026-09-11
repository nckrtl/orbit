<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class DeploymentStepResponse implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $phase,
        #[SensitiveParameter]
        public string $command,
        public int $timeoutSeconds,
    ) {}

    /** @return array{name: string, phase: string, command: string, timeout_seconds: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phase' => $this->phase,
            'command' => $this->command,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    /** @return array{name: string, phase: string, command: string, timeout_seconds: int} */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'phase' => $this->phase,
            'command' => '[COMMAND]',
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    /** @return array{type: class-string<self>, name: string, phase: string, command: string, timeout_seconds: int} */
    public function jsonSerialize(): array
    {
        return ['type' => self::class, ...$this->__debugInfo()];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit deployment step responses cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit deployment step responses cannot be unserialized.');
    }
}
