<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

enum ScheduleErrorCode: string
{
    case NameInvalid = 'schedule.name_invalid';
    case TargetInvalid = 'schedule.target_invalid';
    case TargetUnavailable = 'schedule.target_unavailable';
    case CalendarInvalid = 'schedule.calendar_invalid';
    case CommandInvalid = 'schedule.command_invalid';
    case TimeoutInvalid = 'schedule.timeout_invalid';
    case RetryConflict = 'schedule.retry_conflict';
    case StateInvalid = 'schedule.state_invalid';
    case TargetInUse = 'schedule.target_in_use';
    case ArtifactConflict = 'schedule.artifact_conflict';
    case InstallFailed = 'schedule.install_failed';
    case RollbackFailed = 'schedule.rollback_failed';
    case RunFailed = 'schedule.run_failed';
    case ActivationFailed = 'schedule.activation_failed';
    case LogsFailed = 'schedule.logs_failed';
    case RemoveFailed = 'schedule.remove_failed';
    case NodeUnreachable = 'schedule.node_unreachable';
}
