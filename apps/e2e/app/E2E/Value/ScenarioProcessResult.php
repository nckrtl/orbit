<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class ScenarioProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}
}
