<?php

declare(strict_types=1);

namespace App\Domain\Problems;

final readonly class ProblemLogSignal
{
    public function __construct(
        public string $exceptionClass,
        public string $frame,
        public ?string $requestId,
        public string $excerpt,
        public string $recordedAt,
    ) {}
}
