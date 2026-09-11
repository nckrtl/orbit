<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Apps;

use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

final readonly class AppRuntimeDefinitionResponse
{
    /**
     * @param  list<string>  $environments
     * @param  array<string, mixed>  $spec
     */
    public function __construct(
        public string $id,
        public int $appId,
        public string $name,
        public array $environments,
        public array $spec,
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

        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            appId: is_int($data['app_id'] ?? null) ? $data['app_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            environments: self::stringList($data['environments'] ?? null),
            spec: $redactor->redactArray(self::stringKeyedArray($data['spec'] ?? null)),
            requestId: $requestId,
        );
    }

    /** @return array{id: string, app_id: int, name: string, environments: list<string>, spec: array<string, mixed>, request_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->appId,
            'name' => $this->name,
            'environments' => $this->environments,
            'spec' => $this->spec,
            'request_id' => $this->requestId,
        ];
    }

    /** @return list<string> */
    private static function stringList(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /** @return array<string, mixed> */
    private static function stringKeyedArray(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
