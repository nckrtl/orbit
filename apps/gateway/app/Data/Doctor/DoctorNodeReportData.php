<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Domain\Doctor\DoctorFamilyStatus;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DoctorNodeReportData extends Data
{
    /** @param list<DoctorFamilyReportData> $families */
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public bool $healthy,
        public array $families,
    ) {}

    /** @param list<DoctorFamilyReportData> $families */
    public static function fromFamilies(int $nodeId, string $nodeName, array $families): self
    {
        return new self(
            $nodeId,
            $nodeName,
            ! array_any(
                $families,
                static fn (DoctorFamilyReportData $family): bool => $family->status !== DoctorFamilyStatus::Healthy,
            ),
            $families,
        );
    }
}
