<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

/**
 * @phpstan-import-type InstanceRecordFields from InstanceResponse
 */
final readonly class InstancesResponse
{
    /** @param list<InstanceResponse> $instances */
    public function __construct(
        public array $instances,
        public string $requestId,
    ) {}

    /** @return array{instances: list<InstanceRecordFields>, request_id: string} */
    public function toArray(): array
    {
        return [
            'instances' => array_map(
                static function (InstanceResponse $instance): array {
                    $data = $instance->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->instances,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
