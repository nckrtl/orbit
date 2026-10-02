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
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

final readonly class ProcessDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ProcessStateInspector $inspector,
        private DevelopmentHibernationPolicy $policy = new DevelopmentHibernationPolicy,
        private ?HibernationMarkerStore $markers = null,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Process;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $processes = Process::query()
            ->where(function ($query) use ($context): void {
                $query
                    ->where(function ($query) use ($context): void {
                        $query
                            ->whereIn('owner_type', Instance::morphTypes())
                            ->whereIn(
                                'owner_id',
                                Instance::query()
                                    ->select('id')
                                    ->where('node_id', $context->node->id)
                                    ->where('status', '!=', InstanceState::Removing),
                            );
                    })
                    ->orWhere(function ($query) use ($context): void {
                        $query
                            ->where('owner_type', Node::class)
                            ->where('owner_id', $context->node->id);
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
        $instanceIssues = [];
        $asleep = [];
        foreach ($processes as $process) {
            $issue = null;
            try {
                $inspection = $this->inspector->inspect($process);
            } catch (DoctorInspectionException) {
                $issue = $this->failure($process);
            }

            if (! $issue instanceof DoctorIssueData && ! $inspection->present) {
                $issue = $this->issue(
                    $process,
                    ProcessDoctorIssueCode::RuntimeMissing,
                    DoctorIssueKind::Drift,
                    'present',
                    'absent',
                );
            }

            if (! $issue instanceof DoctorIssueData) {
                $observed = $inspection->status;
                if ($observed === null) {
                    $issue = $this->failure($process);
                } elseif (
                    $process->runtime === ProcessRuntime::Systemd
                    && $process->desired_state === DesiredProcessState::Running
                    && $inspection->isCrashLoop()
                ) {
                    $issue = $this->issue(
                        $process,
                        ProcessDoctorIssueCode::CrashLoop,
                        DoctorIssueKind::Drift,
                        DesiredProcessState::Running->value,
                        $inspection->crashLoopEvidence(),
                    );
                } elseif (! $this->isHealthy($process, $observed, $asleep)) {
                    $issue = $this->issue(
                        $process,
                        ProcessDoctorIssueCode::StateMismatch,
                        DoctorIssueKind::Drift,
                        $process->desired_state->value,
                        $observed->value,
                    );
                }
            }

            if (! $issue instanceof DoctorIssueData) {
                continue;
            }

            if (in_array($process->owner_type, Instance::morphTypes(), strict: true)) {
                $instanceIssues[(int) $process->owner_id][] = $issue;
            } else {
                $issues[] = $issue;
            }

        }

        if ($instanceIssues !== []) {
            $instances = Instance::query()
                ->whereKey(array_keys($instanceIssues))
                ->get()
                ->keyBy('id');

            foreach ($instanceIssues as $instanceId => $ownedIssues) {
                $instance = $instances->get($instanceId);
                if (! $instance instanceof Instance || $instance->status === InstanceState::Removing) {
                    continue;
                }

                $issues = [...$issues, ...$ownedIssues];
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Process, $processes->count(), $issues);
    }

    /** @param array<int, bool> $asleep */
    private function isHealthy(Process $process, ProcessInspectionStatus $observed, array &$asleep): bool
    {
        if ($this->isExpectedHibernation($process, $observed, $asleep)) {
            return true;
        }

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

    /** @param array<int, bool> $asleep */
    private function isExpectedHibernation(Process $process, ProcessInspectionStatus $observed, array &$asleep): bool
    {
        if ($process->keep_alive || $process->desired_state !== DesiredProcessState::Running) {
            return false;
        }

        if (! $this->isObservedStopped($process, $observed) || ! $this->policy->appliesToProcess($process)) {
            return false;
        }

        $owner = $process->owner;

        if (! $owner instanceof Instance) {
            return false;
        }

        $instanceId = (int) $owner->id;

        if (! array_key_exists($instanceId, $asleep)) {
            $markers = $this->markers ?? app(HibernationMarkerStore::class);
            $asleep[$instanceId] = ! $markers->isAwake($owner->node, RuntimeHibernation::key($instanceId));
        }

        return $asleep[$instanceId];
    }

    private function isObservedStopped(Process $process, ProcessInspectionStatus $observed): bool
    {
        return $process->runtime === ProcessRuntime::Systemd
            ? $observed === ProcessInspectionStatus::Inactive
            : in_array(
                $observed,
                [ProcessInspectionStatus::Created, ProcessInspectionStatus::Exited],
                strict: true,
            );
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
            $code === ProcessDoctorIssueCode::CrashLoop
                ? "Process [{$process->name}] is crash-looping while desired running."
                : "Process [{$process->name}] does not match its managed runtime state.",
            $expected,
            $observed,
        );
    }
}
