<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

/** One subtask that a task group create request stores in order. */
final readonly class SubtaskInput
{
    public function __construct(
        public string $title,
        public string $brief,
    ) {}

    /** @return array{title: string, brief: string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'brief' => $this->brief];
    }
}
