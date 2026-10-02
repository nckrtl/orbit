<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ToolInventoryManagerData extends Data
{
    /**
     * @param  list<ToolInventoryPackageData>  $packages
     */
    public function __construct(
        public string $manager,
        public ToolInventoryScanState $scanState,
        public array $packages,
    ) {}

    public static function fromScan(ToolInventoryScan $scan): self
    {
        return new self(
            manager: $scan->manager->value,
            scanState: $scan->scanState,
            packages: array_map(
                ToolInventoryPackageData::fromPackage(...),
                $scan->packages,
            ),
        );
    }
}
