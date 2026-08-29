<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final readonly class ToolInspectionData
{
    public function __construct(
        public bool $installed,
        public ?string $normalizedVersion,
    ) {}
}
