<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

final readonly class AppInstanceEnvironmentResult
{
    public function __construct(
        public int $appInstanceId,
        public string $operation,
        public bool $changed,
        public int $keyCount,
    ) {}

    /** @return array{app_instance_id: int, operation: string, changed: bool, key_count: int} */
    public function toArray(): array
    {
        return [
            'app_instance_id' => $this->appInstanceId,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
        ];
    }
}
