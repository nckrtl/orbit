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
use App\Domain\Doctor\ProjectDoctorIssueCode;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Instances\InstanceProvisionProgress;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Models\Instance;
use App\Models\Project;

final readonly class ProjectDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ProjectStateInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Project;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $eligibleInstanceIds = $this->eligibleInstanceIds($context->node->id);
        $rows = Project::query()
            ->whereIn('id', Instance::query()->whereIn('id', $eligibleInstanceIds)->select('project_id'))
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Project, 0, []);
        }
        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Project, $rows->count(), [new DoctorIssueData(
                ProjectDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'project',
                null,
                null,
                'Project state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )]);
        }
        $issues = [];
        foreach ($rows as $project) {
            try {
                $observation = $this->inspector->inspect($project, $context->node);
                if (! $observation->repositoryOriginsMatch || $observation->failedInstanceIds !== []) {
                    $currentInstanceIds = $this->currentInstanceIds($project, $context->node->id);
                    $mismatchingInstanceIds = $observation->mismatchingInstanceIds === []
                        ? $currentInstanceIds
                        : array_intersect($observation->mismatchingInstanceIds, $currentInstanceIds);
                    $failedInstanceIds = array_intersect($observation->failedInstanceIds, $currentInstanceIds);

                    if (! $observation->repositoryOriginsMatch && $mismatchingInstanceIds !== []) {
                        $issues[] = new DoctorIssueData(
                            ProjectDoctorIssueCode::RepositoryOriginMismatch,
                            DoctorIssueKind::Drift,
                            'project',
                            $project->id,
                            $project->name,
                            'Project repository origin does not match managed identity.',
                            'matching',
                            'mismatch',
                        );
                    }

                    if ($failedInstanceIds !== []) {
                        $issues[] = new DoctorIssueData(
                            ProjectDoctorIssueCode::InspectionFailed,
                            DoctorIssueKind::Unverifiable,
                            'project',
                            $project->id,
                            $project->name,
                            'Project inspection could not be verified.',
                            'verifiable',
                            'unverifiable',
                        );
                    }
                }
            } catch (DoctorInspectionException) {
                if (! $this->hasCheckoutsOutsideRemoval($project, $context->node->id)) {
                    continue;
                }

                $issues[] = new DoctorIssueData(
                    ProjectDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'project',
                    $project->id,
                    $project->name,
                    'Project inspection could not be verified.',
                    'verifiable',
                    'unverifiable',
                );
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Project, $rows->count(), $issues);
    }

    /** @return list<int> */
    private function currentInstanceIds(Project $project, int $nodeId): array
    {
        return $this->eligibleInstanceIds($nodeId, $project->id);
    }

    /** @return list<int> */
    private function eligibleInstanceIds(int $nodeId, ?int $projectId = null): array
    {
        $query = Instance::query()
            ->with(['project', 'tasks'])
            ->whereNull('task_sandbox_id')->where('node_id', $nodeId)
            ->where('status', '!=', InstanceState::Removing);
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $ids = [];
        foreach ($query->get() as $instance) {
            if ($this->isEligible($instance)) {
                $ids[] = $instance->id;
            }
        }

        return $ids;
    }

    private function isEligible(Instance $instance): bool
    {
        $settled = TaskWorkspaceLifecycle::settledState($instance);

        return ! InstanceSandboxGuard::isSandbox($instance) && ! InstanceProvisionProgress::isInFlight($instance, $settled);
    }

    private function hasCheckoutsOutsideRemoval(Project $project, int $nodeId): bool
    {
        return $this->currentInstanceIds($project, $nodeId) !== [];
    }
}
