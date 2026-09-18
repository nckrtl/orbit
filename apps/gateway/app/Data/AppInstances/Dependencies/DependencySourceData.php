<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencySource;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencySourceData extends Data
{
    /** @param array<string, string|null> $fileHashes */
    public function __construct(
        public string $projectRoot,
        public ?string $reference,
        public array $fileHashes,
        public ?string $format,
    ) {}

    public static function fromDomain(DependencySource $source): self
    {
        return new self($source->projectRoot, $source->reference, $source->fileHashes, $source->format);
    }
}
