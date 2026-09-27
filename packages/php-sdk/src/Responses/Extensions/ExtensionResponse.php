<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Extensions;

use InvalidArgumentException;

final readonly class ExtensionResponse
{
    public function __construct(public string $name, public bool $enabled, public string $requestId) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $name = $data['name'] ?? null;
        $enabled = $data['enabled'] ?? null;

        if (! is_string($name) || ! is_bool($enabled)) {
            throw new InvalidArgumentException('Gateway response contains invalid extension state.');
        }

        return new self($name, $enabled, $requestId);
    }

    /** @return array{name: string, enabled: bool, request_id: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'enabled' => $this->enabled, 'request_id' => $this->requestId];
    }
}
