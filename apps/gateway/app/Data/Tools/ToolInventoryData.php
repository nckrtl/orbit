<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Domain\Tools\ToolInventoryReport;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ToolInventoryData extends Data
{
    /**
     * @param  list<ToolInventoryManagerData>  $managers
     */
    public function __construct(
        public int $nodeId,
        public string $observedAt,
        public array $managers,
    ) {}

    public static function fromReport(ToolInventoryReport $report): self
    {
        return new self(
            nodeId: $report->nodeId,
            observedAt: $report->observedAt,
            managers: array_map(
                ToolInventoryManagerData::fromScan(...),
                $report->managers,
            ),
        );
    }
}
