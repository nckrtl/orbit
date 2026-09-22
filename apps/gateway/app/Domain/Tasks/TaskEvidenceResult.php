<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskEvidenceResult
{
    /** @param array<string, float> $probabilities */
    public function __construct(public array $probabilities, public int $inputTokens, public int $durationMs) {}
}
