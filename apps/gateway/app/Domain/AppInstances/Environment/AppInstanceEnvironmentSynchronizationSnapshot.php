<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

final readonly class AppInstanceEnvironmentSynchronizationSnapshot
{
    /** @param array<string, string> $values */
    public function __construct(
        #[\SensitiveParameter]
        private array $values,
    ) {}

    /** @return array<string, string> */
    public function values(): array
    {
        return $this->values;
    }

    public function keyCount(): int
    {
        return count($this->values);
    }

    /** @return array{key_count: int} */
    public function __debugInfo(): array
    {
        return ['key_count' => $this->keyCount()];
    }
}
