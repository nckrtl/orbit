<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Routes;

final readonly class RouteTargetResponse
{
    public function __construct(
        public int $id,
        public int $appInstanceId,
        public int $position,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            appInstanceId: is_int($data['instance_id'] ?? null) ? $data['instance_id'] : 0,
            position: is_int($data['position'] ?? null) ? $data['position'] : 0,
        );
    }

    /** @return array{id: int, instance_id: int, position: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'instance_id' => $this->appInstanceId,
            'position' => $this->position,
        ];
    }
}
