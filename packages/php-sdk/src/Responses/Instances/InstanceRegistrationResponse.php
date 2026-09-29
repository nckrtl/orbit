<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use Orbit\Sdk\Responses\Projects\ProjectResponse;
use SensitiveParameter;

final readonly class InstanceRegistrationResponse
{
    /**
     * @param  list<InstanceResponse>  $instances
     */
    public function __construct(
        public ProjectResponse $project,
        public InstanceResponse $instance,
        public array $instances,
        public string $status,
        public int $sourceCount,
        public int $completedCount,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $project = self::stringArray($data['project'] ?? null);
        $primary = self::stringArray($data['instance'] ?? null);
        $rows = is_array($data['instances'] ?? null) ? $data['instances'] : [];
        $instances = [];

        foreach ($rows as $row) {
            $instance = self::stringArray($row);

            if ($instance !== []) {
                $instances[] = InstanceResponse::fromGatewayData($instance, $requestId);
            }
        }

        return new self(
            project: ProjectResponse::fromGatewayData($project, $requestId),
            instance: InstanceResponse::fromGatewayData($primary, $requestId),
            instances: $instances,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            sourceCount: is_int($data['source_count'] ?? null) ? $data['source_count'] : 0,
            completedCount: is_int($data['completed_count'] ?? null) ? $data['completed_count'] : 0,
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'project' => $this->project->toArray(),
            'instance' => $this->instance->toArray(),
            'instances' => array_map(
                static fn (InstanceResponse $instance): array => $instance->toArray(),
                $this->instances,
            ),
            'status' => $this->status,
            'source_count' => $this->sourceCount,
            'completed_count' => $this->completedCount,
            'request_id' => $this->requestId,
        ];
    }

    /** @return array<string, mixed> */
    private static function stringArray(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            return [];
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
