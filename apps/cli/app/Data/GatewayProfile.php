<?php

declare(strict_types=1);

namespace App\Data;

final readonly class GatewayProfile
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $caPath = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            caPath: is_string($data['ca_path'] ?? null) ? $data['ca_path'] : null,
        );
    }

    /** @return array{url: string, ca_path: ?string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'ca_path' => $this->caPath,
        ];
    }
}
