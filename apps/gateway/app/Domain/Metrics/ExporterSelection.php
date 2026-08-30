<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

final readonly class ExporterSelection
{
    public function __construct(
        public bool $selected,
        public ExporterSelectionReason $reason,
    ) {}
}
