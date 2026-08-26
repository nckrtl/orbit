<?php

declare(strict_types=1);

namespace App\Data;

final readonly class NodeSetupExecutionResult
{
    public function __construct(
        public int $exitCode,
        public string $diagnostics,
        public bool $interrupted = false,
    ) {}
}
