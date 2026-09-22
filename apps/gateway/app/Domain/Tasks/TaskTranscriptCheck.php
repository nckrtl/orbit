<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskTranscriptCheck
{
    public function __construct(
        public string $key,
        public string $choice,
        public float $confidence,
    ) {}
}
