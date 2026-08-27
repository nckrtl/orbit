<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Apps;

use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

/** @mago-expect lint:excessive-parameter-list */
final readonly class AppResponse
{
    /** @param array<array-key, mixed>|null $defaults */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $repositoryUrl,
        public ?array $defaults,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $redactor = new CredentialRedactor;
        $defaults = self::arrayValue($data['defaults'] ?? null);

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            slug: is_string($data['slug'] ?? null) ? $data['slug'] : '',
            repositoryUrl: is_string($data['repository_url'] ?? null)
                ? $redactor->redactText($data['repository_url'])
                : '',
            defaults: $defaults === null ? null : $redactor->redactTransportArray($defaults),
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
     * @return array<array-key, mixed>|null
     */
    private static function arrayValue(#[SensitiveParameter] mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
