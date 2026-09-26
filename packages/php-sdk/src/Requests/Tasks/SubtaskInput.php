<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

/** One subtask that a task group create request stores in order, with its typed deliverables. */
final readonly class SubtaskInput
{
    /** @param list<array<string, string|bool>> $deliverables */
    public function __construct(
        public string $title,
        public string $brief,
        public array $deliverables = [],
    ) {}

    /** @return array{title: string, brief: string, deliverables?: list<array<string, string|bool>>} */
    public function toArray(): array
    {
        return $this->deliverables === []
            ? ['title' => $this->title, 'brief' => $this->brief]
            : ['title' => $this->title, 'brief' => $this->brief, 'deliverables' => $this->deliverables];
    }
}
