<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Environment;

use LogicException;
use Saloon\Http\Response;
use SensitiveParameter;

/** @internal */
final class EnvironmentTransportResponse extends Response
{
    /** @return array{type: class-string<self>, status: int} */
    public function __debugInfo(): array
    {
        return ['type' => self::class, 'status' => $this->status()];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit environment transport responses cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit environment transport responses cannot be unserialized.');
    }
}
