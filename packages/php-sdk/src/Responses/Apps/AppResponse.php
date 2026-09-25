<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Apps;

use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

final readonly class AppResponse
{
    /** @param array<array-key, mixed>|null $defaults */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $type,
        public string $repositoryUrl,
        public ?string $defaultBranch,
        public ?string $root,
        public ?array $defaults,
        public string $requestId,
        /** @var list<array{project_id: int, project_slug: string, node_id: int, node_name: string, development_instance_count: int}>|null */
        public ?array $excludedNodes = null,
        public ?string $taskCheck = null,
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
            type: is_string($data['type'] ?? null) ? $data['type'] : 'laravel-app',
            repositoryUrl: is_string($data['repository_url'] ?? null)
                ? $redactor->redactText($data['repository_url'])
                : '',
            defaultBranch: is_string($data['default_branch'] ?? null) ? $data['default_branch'] : null,
            root: is_string($data['root'] ?? null) ? $data['root'] : null,
            defaults: $defaults === null ? null : $redactor->redactTransportArray($defaults),
            requestId: $requestId,
            excludedNodes: self::exclusions($data['excluded_nodes'] ?? null),
            taskCheck: is_string($data['task_check'] ?? null) ? $data['task_check'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'repository_url' => $this->repositoryUrl,
            'default_branch' => $this->defaultBranch,
            'root' => $this->root,
            'task_check' => $this->taskCheck,
            'defaults' => $this->defaults,
            'request_id' => $this->requestId,
            ...($this->excludedNodes === null ? [] : ['excluded_nodes' => $this->excludedNodes]),
        ];
    }

    /**
     * @return list<array{project_id: int, project_slug: string, node_id: int, node_name: string, development_instance_count: int}>|null
     */
    private static function exclusions(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $projectId = $item['project_id'] ?? null;
            $nodeId = $item['node_id'] ?? null;
            $count = $item['development_instance_count'] ?? null;
            $projectSlug = $item['project_slug'] ?? null;
            $nodeName = $item['node_name'] ?? null;

            if (! is_int($projectId) || ! is_int($nodeId) || ! is_int($count) || ! is_string($projectSlug) || ! is_string($nodeName)) {
                continue;
            }

            $result[] = [
                'project_id' => $projectId,
                'project_slug' => $projectSlug,
                'node_id' => $nodeId,
                'node_name' => $nodeName,
                'development_instance_count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function arrayValue(#[SensitiveParameter] mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
