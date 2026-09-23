<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskTranscriptCheck
{
    /** @param array<string, float> $probabilities */
    public function __construct(
        public string $key,
        public string $choice,
        public float $confidence,
        public array $probabilities = [],
    ) {}
}
