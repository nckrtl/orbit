<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

/** One subtask that a task group create request stores in order, with its typed deliverables. */
final readonly class SubtaskInput
{
    /**
     * @param  list<array<string, string|bool|list<string>>>  $deliverables
     * @param  list<string>|null  $topology
     */
    public function __construct(
        public string $title,
        public string $brief,
        public array $deliverables = [],
        public ?array $topology = null,
    ) {}

    /** @return array{title: string, brief: string, deliverables?: list<array<string, string|bool|list<string>>>, topology?: list<string>} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'brief' => $this->brief,
            ...($this->deliverables === [] ? [] : ['deliverables' => $this->deliverables]),
            ...($this->topology === null ? [] : ['topology' => $this->topology]),
        ];
    }
}
