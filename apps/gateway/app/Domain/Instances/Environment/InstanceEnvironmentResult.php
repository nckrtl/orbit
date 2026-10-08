<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

final readonly class InstanceEnvironmentResult
{
    public function __construct(
        public int $instanceId,
        public string $operation,
        public bool $changed,
        public int $keyCount,
        public ?InstanceTestEnvironmentOutcome $testing = null,
    ) {}

    /** @return array{instance_id: int, operation: string, changed: bool, key_count: int} */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
        ];
    }
}
