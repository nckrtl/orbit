<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class AgentObservation
{
    /**
     * @param  list<AgentInputRequest>  $inputRequests
     * @param  list<array{id: string, kind: string, label: string, text: string, at: string}>  $entries
     */
    public function __construct(
        public ?AgentThreadState $state,
        public array $inputRequests = [],
        public array $entries = [],
        public ?int $tokens = null,
        public ?int $linesAdded = null,
        public ?int $linesDeleted = null,
        public ?string $error = null,
        public ?string $cursor = null,
    ) {}

    public function lastText(string $role): ?string
    {
        foreach (array_reverse($this->entries) as $entry) {
            if ($entry['kind'] === 'message' && $entry['label'] === $role) {
                return mb_substr($entry['text'], 0, 400);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state?->value,
            'input_requests' => array_map(static fn (AgentInputRequest $request): array => $request->toArray(), $this->inputRequests),
            'entries' => $this->entries,
            'tokens' => $this->tokens,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'error' => $this->error,
        ];
    }
}
