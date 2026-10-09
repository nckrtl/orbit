<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

final readonly class ProjectRuntimeDefinitionResponse
{
    /**
     * @param  list<string>  $environments
     * @param  array<string, mixed>  $spec
     */
    public function __construct(
        public string $id,
        public int $projectId,
        public string $name,
        public array $environments,
        public array $spec,
        public string $requestId,
        public ?string $app = null,
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
            projectId: is_int($data['project_id'] ?? null) ? $data['project_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            environments: self::stringList($data['environments'] ?? null),
            spec: $redactor->redactArray(self::stringKeyedArray($data['spec'] ?? null)),
            requestId: $requestId,
            app: is_string($data['app'] ?? null) ? $data['app'] : null,
        );
    }

    /** @return array{id: string, project_id: int, app: string|null, name: string, environments: list<string>, spec: array<string, mixed>, request_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'app' => $this->app,
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
