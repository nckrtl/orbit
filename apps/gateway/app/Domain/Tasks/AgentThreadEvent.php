<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class AgentThreadEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $threadId,
        public string $kind,
        public array $data = [],
        public ?string $cursor = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->data, 'thread_id' => $this->threadId, 'kind' => $this->kind, 'cursor' => $this->cursor];
    }
}
