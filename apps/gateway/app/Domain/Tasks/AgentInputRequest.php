<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class AgentInputRequest
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $id,
        public string $kind,
        public array $details = [],
    ) {}

    /** @return array{id: string, kind: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'details' => $this->details];
    }
}
