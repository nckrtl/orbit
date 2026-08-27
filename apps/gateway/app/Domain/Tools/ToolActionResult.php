<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\Tool;

final readonly class ToolActionResult
{
    public function __construct(
        public Tool $tool,
        public ToolOutcome $outcome,
        public bool $created = false,
    ) {}
}
