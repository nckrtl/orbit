<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyResolution;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencyResolutionData extends Data
{
    public function __construct(
        public string $id,
        public string $ecosystem,
        public string $name,
        public string $version,
        public bool $regular,
        public bool $development,
        public ?string $sourceReference,
        public ?string $integrity,
    ) {}

    public static function fromDomain(DependencyResolution $resolution): self
    {
        return new self($resolution->id, $resolution->package->ecosystem->value, $resolution->package->name,
            $resolution->version, $resolution->regular, $resolution->development, $resolution->sourceReference, $resolution->integrity);
    }
}
