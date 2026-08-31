<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class AppsSettings
{
    public function __construct(
        public ?string $path = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(is_string($data['path'] ?? null) ? $data['path'] : null);
    }

    /** @return array{path: string|null} */
    public function toArray(): array
    {
        return ['path' => $this->path];
    }
}
