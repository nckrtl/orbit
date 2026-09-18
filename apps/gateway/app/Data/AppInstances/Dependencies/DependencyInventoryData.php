<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencyInventoryData extends Data
{
    public function __construct(
        public string $ecosystem,
        public string $state,
        public ?bool $succeeded,
        public ?string $attemptedAt,
        public ?string $errorCode,
        public ?DependencySnapshotData $snapshot,
    ) {}

    public static function fromResult(DependencyEcosystem $ecosystem, ?DependencyScanResult $result): self
    {
        return new self(
            $ecosystem->value, $result?->state()->value ?? 'unknown', $result?->succeeded(),
            $result?->attemptedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339),
            $result?->errorCode,
            $result?->snapshot === null ? null : DependencySnapshotData::fromDomain($result->snapshot),
        );
    }
}
