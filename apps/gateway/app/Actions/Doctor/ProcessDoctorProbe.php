<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\ProcessDoctorIssueCode;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Models\Instance;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final readonly class ProcessDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ProcessStateInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Process;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $processes = Process::query()
            ->where(static function (Builder $query) use ($context): void {
                $query
                    ->where(static function (Builder $query) use ($context): void {
                        $query
                            ->where('owner_type', Instance::class)
                            ->whereIn('owner_id', Instance::query()
                                ->select('id')
                                ->where('node_id', $context->node->id));
                    })
                    ->orWhere(static function (Builder $query) use ($context): void {
                        $query
                            ->where('owner_type', Workspace::class)
                            ->whereIn(
                                'owner_id',
                                Workspace::query()
                                    ->whereHas('instance', static fn (Builder $query): Builder => $query->where(
                                        'node_id',
                                        $context->node->id,
                                    ))
                                    ->select('id'),
                            );
                    });
            })
            ->orderBy('id')
            ->get();

        if ($processes->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Process, 0, []);
        }

        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(
                DoctorFamily::Process,
                $processes->count(),
                [new DoctorIssueData(
                    ProcessDoctorIssueCode::NodeUnreachable,
                    DoctorIssueKind::Unverifiable,
                    'process',
                    null,
                    null,
                    'Process runtime state cannot be inspected because the node is unreachable.',
                    'reachable',
                    'unreachable',
                )],
            );
        }

        $issues = [];
        foreach ($processes as $process) {
            try {
                $inspection = $this->inspector->inspect($process);
            } catch (DoctorInspectionException) {
                $issues[] = $this->failure($process);
                continue;
            }

            if (! $inspection->present) {
                $issues[] = $this->issue(
                    $process,
                    ProcessDoctorIssueCode::RuntimeMissing,
                    DoctorIssueKind::Drift,
                    'present',
                    'absent',
                );
                continue;
            }

            $observed = $inspection->status;
            if ($observed === null) {
                $issues[] = $this->failure($process);
                continue;
            }

            if ($this->isHealthy($process, $observed)) {
                continue;
            }

            $issues[] = $this->issue(
                $process,
                ProcessDoctorIssueCode::StateMismatch,
                DoctorIssueKind::Drift,
                $process->desired_state->value,
                $observed->value,
            );
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Process, $processes->count(), $issues);
    }

    private function isHealthy(Process $process, ProcessInspectionStatus $observed): bool
    {
        return match ($process->desired_state) {
            DesiredProcessState::Running => $observed
                === (
                    $process->runtime === ProcessRuntime::Systemd
                        ? ProcessInspectionStatus::Active
                        : ProcessInspectionStatus::Running
                ),
            DesiredProcessState::Stopped => $process->runtime === ProcessRuntime::Systemd
                ? $observed === ProcessInspectionStatus::Inactive
                : in_array(
                    $observed,
                    [ProcessInspectionStatus::Created, ProcessInspectionStatus::Exited],
                    strict: true,
                ),
        };
    }

    private function failure(Process $process): DoctorIssueData
    {
        return $this->issue(
            $process,
            ProcessDoctorIssueCode::InspectionFailed,
            DoctorIssueKind::Unverifiable,
            null,
            null,
        );
    }

    private function issue(
        Process $process,
        ProcessDoctorIssueCode $code,
        DoctorIssueKind $kind,
        bool|string|null $expected,
        bool|string|null $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            'process',
            $process->id,
            $process->name,
            "Process [{$process->name}] does not match its managed runtime state.",
            $expected,
            $observed,
        );
    }
}
