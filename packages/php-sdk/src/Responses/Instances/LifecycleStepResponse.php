<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

final readonly class LifecycleStepResponse implements JsonSerializable
{
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $command,
        public int $timeoutSeconds,
        public string $requestId = '',
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromData(array $data, string $requestId = ''): self
    {
        return new self(
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['command'] ?? null) ? $data['command'] : '',
            is_int($data['timeout_seconds'] ?? null) ? $data['timeout_seconds'] : 0,
            $requestId,
        );
    }

    /** @return array{name: string, command: string, timeout_seconds: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    /** @return array{name: string, command: string, timeout_seconds: int} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return array{name: string, command: string, timeout_seconds: int} */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'command' => '[COMMAND]', 'timeout_seconds' => $this->timeoutSeconds];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit lifecycle step responses cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit lifecycle step responses cannot be unserialized.');
    }
}
