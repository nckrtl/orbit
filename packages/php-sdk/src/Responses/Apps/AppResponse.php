<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Apps;

/** @mago-expect lint:excessive-parameter-list */
final readonly class AppResponse
{
    /** @param array<string, mixed>|null $defaults */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $repositoryUrl,
        public ?array $defaults,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            slug: is_string($data['slug'] ?? null) ? $data['slug'] : '',
            repositoryUrl: is_string($data['repository_url'] ?? null) ? $data['repository_url'] : '',
            defaults: self::stringKeyedArray($data['defaults'] ?? null),
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'repository_url' => $this->repositoryUrl,
            'defaults' => $this->defaults,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway default values remain mixed by design.
     *
     * @return array<string, mixed>|null
     */
    private static function stringKeyedArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
