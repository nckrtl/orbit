<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskDefinitionViolation
{
    public function __construct(
        public string $rule,
        public ?string $subtask,
    ) {}

    /** @return array{rule: string, subtask: string|null} */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'subtask' => $this->subtask];
    }
}
