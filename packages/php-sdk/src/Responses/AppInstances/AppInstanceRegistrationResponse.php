<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use Orbit\Sdk\Responses\Apps\AppResponse;
use SensitiveParameter;

final readonly class AppInstanceRegistrationResponse
{
    /**
     * @param  list<AppInstanceResponse>  $appInstances
     */
    public function __construct(
        public AppResponse $app,
        public AppInstanceResponse $appInstance,
        public array $appInstances,
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
        $app = self::stringArray($data['app'] ?? null);
        $primary = self::stringArray($data['app_instance'] ?? null);
        $rows = is_array($data['app_instances'] ?? null) ? $data['app_instances'] : [];
        $instances = [];

        foreach ($rows as $row) {
            $instance = self::stringArray($row);

            if ($instance !== []) {
                $instances[] = AppInstanceResponse::fromGatewayData($instance, $requestId);
            }
        }

        return new self(
            app: AppResponse::fromGatewayData($app, $requestId),
            appInstance: AppInstanceResponse::fromGatewayData($primary, $requestId),
            appInstances: $instances,
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
            'app' => $this->app->toArray(),
            'app_instance' => $this->appInstance->toArray(),
            'app_instances' => array_map(
                static fn (AppInstanceResponse $instance): array => $instance->toArray(),
                $this->appInstances,
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
