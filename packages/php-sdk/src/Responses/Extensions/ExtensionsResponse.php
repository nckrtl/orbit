<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Extensions;

use InvalidArgumentException;

final readonly class ExtensionsResponse
{
    /** @param array<string, bool> $extensions */
    public function __construct(public array $extensions, public string $requestId) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        if ($data === []) {
            throw new InvalidArgumentException('Gateway response contains invalid extension state.');
        }

        foreach ($data as $slug => $enabled) {
            if (! is_bool($enabled)) {
                throw new InvalidArgumentException('Gateway response contains invalid extension state.');
            }
        }

        return new self($data, $requestId);
    }

    /** @return array{extensions: array<string, bool>, request_id: string} */
    public function toArray(): array
    {
        return ['extensions' => $this->extensions, 'request_id' => $this->requestId];
    }
}
