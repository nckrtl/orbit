<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

final readonly class AppInstancesResponse
{
    /** @param list<AppInstanceResponse> $appInstances */
    public function __construct(
        public array $appInstances,
        public string $requestId,
    ) {}

    /** @return array{app_instances: list<array<string, int|string|null|array<string, mixed>>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'app_instances' => array_map(
                static function (AppInstanceResponse $appInstance): array {
                    $data = $appInstance->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->appInstances,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
