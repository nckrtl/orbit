<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use SensitiveParameter;

/** The source Instance named beside a development copy. */
final readonly class InstanceSourceIdentityResponse
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function tryFromGatewayData(
        #[SensitiveParameter]
        mixed $data,
    ): ?self {
        if (
            ! is_array($data)
            || ! is_int($data['id'] ?? null)
            || $data['id'] < 1
            || ! is_string($data['name'] ?? null)
            || $data['name'] === ''
        ) {
            return null;
        }

        return new self($data['id'], $data['name']);
    }

    /** @return array{id: int, name: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
