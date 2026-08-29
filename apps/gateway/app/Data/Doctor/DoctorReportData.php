<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Domain\Doctor\DoctorIssueKind;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DoctorReportData extends Data
{
    /** @param list<DoctorNodeReportData> $nodes */
    public function __construct(
        public bool $healthy,
        public array $nodes,
        public array $summary,
    ) {}

    /** @param list<DoctorNodeReportData> $nodes */
    public static function fromNodes(array $nodes): self
    {
        $families = [];
        foreach ($nodes as $node) {
            $families = [...$families, ...$node->families];
        }

        return new self(! array_any($nodes, static fn (DoctorNodeReportData $node): bool => ! $node->healthy), $nodes, [
            'nodes' => count($nodes),
            'families' => count($families),
            'checks' => array_sum(array_map(
                static fn (DoctorFamilyReportData $family): int => $family->checked,
                $families,
            )),
            'drift' => array_sum(array_map(static fn (DoctorFamilyReportData $family): int => count(array_filter(
                $family->issues,
                static fn (DoctorIssueData $issue): bool => $issue->kind === DoctorIssueKind::Drift,
            )), $families)),
            'unverifiable' => array_sum(array_map(static fn (DoctorFamilyReportData $family): int => count(array_filter(
                $family->issues,
                static fn (DoctorIssueData $issue): bool => $issue->kind === DoctorIssueKind::Unverifiable,
            )), $families)),
        ]);
    }
}
