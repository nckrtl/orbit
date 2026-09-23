<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TasksStatusResponse
{
    public function __construct(
        public bool $enabled,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $enabled = $data['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw TaskFields::invalid('tasks extension status', $requestId);
        }

        return new self($enabled, $requestId);
    }

    /** @return array{enabled: bool, request_id: string} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'request_id' => $this->requestId,
        ];
    }
}
