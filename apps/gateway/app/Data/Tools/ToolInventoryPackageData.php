<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ToolInventoryPackageData extends Data
{
    public function __construct(
        public string $manager,
        public string $package,
        public ToolInventoryPackageKind $packageKind,
        public ?string $installedVersion,
        public bool $dependency,
        public bool $registered,
        public ?int $toolId,
        public string $adoption,
        public ?string $adoptionBlock,
    ) {}

    public static function fromPackage(ToolInventoryPackage $package): self
    {
        return new self(
            manager: $package->manager->value,
            package: $package->package,
            packageKind: $package->packageKind,
            installedVersion: $package->installedVersion,
            dependency: $package->dependency,
            registered: $package->registered,
            toolId: $package->toolId,
            adoption: $package->adoption,
            adoptionBlock: $package->adoptionBlock,
        );
    }
}
