<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final readonly class ToolInspectionOutcome
{
    private function __construct(
        private ?ToolInspectionData $data,
    ) {}

    public static function verified(ToolInspectionData $data): self
    {
        return new self($data);
    }

    public static function failed(): self
    {
        return new self(null);
    }

    public function data(): ToolInspectionData
    {
        return $this->data ?? throw new ToolInspectionException;
    }
}
