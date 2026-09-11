<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum ScheduleDoctorIssueCode: string implements DoctorIssueCode
{
    case ArtifactMissing = 'schedule.artifact_missing';
    case ArtifactPermissionsMismatch = 'schedule.artifact_permissions_mismatch';
    case SpecificationMismatch = 'schedule.specification_mismatch';
    case TimerStateMismatch = 'schedule.timer_state_mismatch';
    case CalendarMismatch = 'schedule.calendar_mismatch';
    case ExecutionContextMismatch = 'schedule.execution_context_mismatch';
    case CompletionCallbackMismatch = 'schedule.completion_callback_mismatch';
    case PlacementMismatch = 'schedule.placement_mismatch';
    case OrphanArtifact = 'schedule.orphan_artifact';
    case NodeUnreachable = 'schedule.node_unreachable';
    case InspectionFailed = 'schedule.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Schedule;
    }
}
