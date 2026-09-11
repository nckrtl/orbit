<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\ScheduleDoctorIssueCode;
use App\Domain\Doctor\ScheduleInspectionData;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Models\Schedule;

final readonly class ScheduleDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ScheduleStateInspector $inspector,
        private ScheduleTargetResolver $targets,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Schedule;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $schedules = Schedule::query()->where('host_node_id', $context->node->id)->orderBy('id')->get();

        if (! $context->inspection->reachable && $schedules->isNotEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, $schedules->count(), [
                new DoctorIssueData(
                    ScheduleDoctorIssueCode::NodeUnreachable,
                    DoctorIssueKind::Unverifiable,
                    'schedule',
                    null,
                    null,
                    'Schedule state cannot be inspected because the Node is unreachable.',
                    'reachable',
                    'unreachable',
                ),
            ]);
        }

        $issues = [];

        foreach ($schedules as $schedule) {
            try {
                $target = $this->targets->forInspection($schedule);

                if ($target->node->id !== $schedule->host_node_id) {
                    $issues[] = $this->issue($schedule, ScheduleDoctorIssueCode::PlacementMismatch);

                    continue;
                }

                $inspection = $this->inspector->inspect($schedule);
                $issues = [...$issues, ...$this->inspectionIssues($schedule, $inspection)];
            } catch (\Throwable) {
                $issues[] = $this->issue(
                    $schedule,
                    ScheduleDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                );
            }
        }

        try {
            foreach ($this->inspector->orphanIds($context->node, $schedules->pluck('id')->all()) as $id) {
                $issues[] = new DoctorIssueData(
                    ScheduleDoctorIssueCode::OrphanArtifact,
                    DoctorIssueKind::Drift,
                    'schedule',
                    $id,
                    null,
                    'An Orbit Schedule artifact has no matching Schedule intent.',
                    'absent',
                    'present',
                );
            }
        } catch (\Throwable) {
            $issues[] = new DoctorIssueData(
                ScheduleDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'schedule',
                null,
                null,
                'Schedule artifact inventory could not be inspected.',
                'verifiable',
                'unverifiable',
            );
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, $schedules->count(), $issues);
    }

    /** @return list<DoctorIssueData> */
    private function inspectionIssues(Schedule $schedule, ScheduleInspectionData $inspection): array
    {
        if (! $inspection->artifactsPresent) {
            return [$this->issue($schedule, ScheduleDoctorIssueCode::ArtifactMissing)];
        }

        $checks = [
            [ScheduleDoctorIssueCode::ArtifactPermissionsMismatch, $inspection->permissionsMatch],
            [ScheduleDoctorIssueCode::SpecificationMismatch, $inspection->specificationMatch],
            [ScheduleDoctorIssueCode::TimerStateMismatch, $inspection->timerStateMatch],
            [ScheduleDoctorIssueCode::CalendarMismatch, $inspection->calendarMatch],
            [ScheduleDoctorIssueCode::ExecutionContextMismatch, $inspection->executionContextMatch],
            [ScheduleDoctorIssueCode::CompletionCallbackMismatch, $inspection->completionCallbackMatch],
        ];
        $issues = [];

        foreach ($checks as [$code, $matches]) {
            if (! $matches) {
                $issues[] = $this->issue($schedule, $code);
            }
        }

        return $issues;
    }

    private function issue(
        Schedule $schedule,
        ScheduleDoctorIssueCode $code,
        DoctorIssueKind $kind = DoctorIssueKind::Drift,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            'schedule',
            $schedule->id,
            $schedule->name,
            'Schedule intent does not match its bounded host observation.',
            $kind === DoctorIssueKind::Unverifiable ? 'verifiable' : 'matching',
            $kind === DoctorIssueKind::Unverifiable ? 'unverifiable' : 'mismatch',
        );
    }
}
