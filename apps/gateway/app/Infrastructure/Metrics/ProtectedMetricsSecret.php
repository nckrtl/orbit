<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Infrastructure\Processes\ProtectedInput;

final readonly class ProtectedMetricsSecret implements \Stringable
{
    public function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {}

    public function input(): ProtectedInput
    {
        return ProtectedInput::fromString($this->value);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->value);
    }

    public function __debugInfo(): array
    {
        return ['value' => '[PROTECTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Protected metrics secret cannot be serialized.');
    }

    public function __toString(): string
    {
        return '[PROTECTED]';
    }
}
