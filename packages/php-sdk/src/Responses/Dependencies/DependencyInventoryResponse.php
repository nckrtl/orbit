<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencyInventoryResponse
{
    public function __construct(
        public string $ecosystem,
        public string $state,
        public ?bool $succeeded,
        public ?string $attemptedAt,
        public ?string $errorCode,
        public ?DependencySnapshotResponse $snapshot,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ecosystem' => $this->ecosystem,
            'state' => $this->state,
            'succeeded' => $this->succeeded,
            'attempted_at' => $this->attemptedAt,
            'error_code' => $this->errorCode,
            'snapshot' => $this->snapshot?->toArray(),
        ];
    }
}
