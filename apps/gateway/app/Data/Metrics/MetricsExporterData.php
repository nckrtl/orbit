<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Domain\Metrics\ExporterSelectionReason;

final readonly class MetricsExporterData
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $desired,
        public string $actual,
        public ExporterSelectionReason $reason,
    ) {}

    /** @return array{id: int, name: string, desired: bool, actual: string, reason: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'desired' => $this->desired,
            'actual' => $this->actual,
            'reason' => $this->reason->value,
        ];
    }
}
