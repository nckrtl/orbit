<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class InstanceDependencyUpdateResponse
{
    public function __construct(
        public int $instanceId,
        public bool $succeeded,
        public ?string $errorCode,
        public bool $mayHaveMutated,
        public DependencyUpdateStepResponse $composer,
        public DependencyUpdateStepResponse $javascript,
        public ?InstanceDependencyInventoryResponse $inventory,
        public string $requestId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'succeeded' => $this->succeeded,
            'error_code' => $this->errorCode,
            'may_have_mutated' => $this->mayHaveMutated,
            'composer' => $this->composer->toArray(),
            'javascript' => $this->javascript->toArray(),
            'inventory' => $this->inventory === null ? null : [
                'instance_id' => $this->inventory->instanceId,
                'succeeded' => $this->inventory->succeeded,
                'composer' => $this->inventory->composer->toArray(),
                'javascript' => $this->inventory->javascript->toArray(),
            ],
            'request_id' => $this->requestId,
        ];
    }
}
