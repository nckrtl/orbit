<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskRubricItem
{
    public function __construct(
        public string $key,
        public bool $passed,
        public string $reminder,
        public ?string $choice = null,
        public ?float $confidence = null,
    ) {}
}
