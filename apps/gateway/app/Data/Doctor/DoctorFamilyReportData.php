<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyStatus;
use App\Domain\Doctor\DoctorIssueKind;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DoctorFamilyReportData extends Data
{
    /** @param list<DoctorIssueData> $issues */
    public function __construct(
        public DoctorFamily $family,
        public DoctorFamilyStatus $status,
        public int $checked,
        public array $issues,
    ) {}

    /** @param list<DoctorIssueData> $issues */
    public static function fromIssues(DoctorFamily $family, int $checked, array $issues): self
    {
        $status = DoctorFamilyStatus::Healthy;
        if (count($issues) > 0) {
            $status = DoctorFamilyStatus::Drift;
        }
        if (array_any(
            $issues,
            static fn (DoctorIssueData $issue): bool => $issue->kind === DoctorIssueKind::Unverifiable,
        )) {
            $status = DoctorFamilyStatus::Unverifiable;
        }

        return new self($family, $status, $checked, $issues);
    }
}
