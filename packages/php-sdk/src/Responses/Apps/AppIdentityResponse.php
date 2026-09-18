<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Apps;

/** The identity of a related App as the Gateway names it beside a record. */
final readonly class AppIdentityResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}

    public static function tryFromGatewayData(
        #[\SensitiveParameter]
        mixed $data,
    ): ?self {
        if (
            ! is_array($data)
            || ! is_int($data['id'] ?? null)
            || $data['id'] < 1
            || ! is_string($data['name'] ?? null)
            || ! is_string($data['slug'] ?? null)
            || $data['slug'] === ''
        ) {
            return null;
        }

        return new self(id: $data['id'], name: $data['name'], slug: $data['slug']);
    }

    /** @return array{id: int, name: string, slug: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug];
    }
}
