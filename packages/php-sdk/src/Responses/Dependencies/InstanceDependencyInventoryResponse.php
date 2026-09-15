<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class InstanceDependencyInventoryResponse
{
    public function __construct(
        public int $instanceId,
        public ?bool $succeeded,
        public DependencyInventoryResponse $composer,
        public DependencyInventoryResponse $javascript,
        public string $requestId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'succeeded' => $this->succeeded,
            'composer' => $this->composer->toArray(),
            'javascript' => $this->javascript->toArray(),
            'request_id' => $this->requestId,
        ];
    }
}
