<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\ToolDoctorIssueCode;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\VersionConstraint;
use App\Models\Tool;
use Illuminate\Database\Eloquent\Collection;

final readonly class ToolDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ToolInspector $inspector,
        private VersionConstraint $constraints,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Tool;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $tools = Tool::query()
            ->with(['node', 'manager'])
            ->where('node_id', $context->node->id)
            ->orderBy('id')
            ->get();
        $reachable = $context->inspection->reachable;

        return $this->inspectTools($tools, $reachable);
    }

    /** @param Collection<int, Tool> $tools */
    /** @mago-expect lint:no-boolean-flag-parameter The context contract exposes one reachability gate. */
    private function inspectTools(Collection $tools, bool $reachable): DoctorFamilyReportData
    {
        if ($tools->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Tool, 0, []);
        }

        if (! $reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Tool, count($tools), [
                new DoctorIssueData(
                    ToolDoctorIssueCode::NodeUnreachable,
                    DoctorIssueKind::Unverifiable,
                    'tool',
                    null,
                    null,
                    'Tool node is unreachable.',
                    null,
                    'unreachable',
                ),
            ]);
        }

        /** @var list<DoctorIssueData> $issues */
        $issues = [];
        foreach ($tools as $tool) {
            /** @var Tool $tool */
            try {
                $inspection = $this->inspector->inspect($tool);
                if (! $inspection->installed) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::NotInstalled,
                        DoctorIssueKind::Drift,
                        'tool',
                        $tool->id,
                        null,
                        'Managed tool is not installed.',
                        true,
                        false,
                    );
                    continue;
                }

                /** @mago-expect analysis:mixed-assignment Eloquent attributes are runtime-cast. */
                $constraint = $tool->getAttribute('version_constraint');
                if ($constraint !== null && ! is_string($constraint)) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::InspectionFailed,
                        DoctorIssueKind::Unverifiable,
                        'tool',
                        (int) $tool->id,
                        null,
                        'Tool inspection could not be verified.',
                        'verifiable',
                        'unverifiable',
                    );
                    continue;
                }

                if ($constraint === null) {
                    continue;
                }

                if (
                    ! $this->constraints->isValid($constraint)
                    || $inspection->normalizedVersion === null
                ) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::InspectionFailed,
                        DoctorIssueKind::Unverifiable,
                        'tool',
                        $tool->id,
                        null,
                        'Tool inspection could not be verified.',
                        'verifiable',
                        'unverifiable',
                    );
                    continue;
                }

                if (! $this->constraints->allows($inspection->normalizedVersion, $constraint)) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::VersionMismatch,
                        DoctorIssueKind::Drift,
                        'tool',
                        $tool->id,
                        null,
                        'Installed tool version does not satisfy intent.',
                        'satisfied',
                        'rejected',
                    );
                }
            } catch (ToolInspectionException) {
                $issues[] = new DoctorIssueData(
                    ToolDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'tool',
                    $tool->id,
                    null,
                    'Tool inspection could not be verified.',
                    'verifiable',
                    'unverifiable',
                );
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Tool, count($tools), $issues);
    }
}
