<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Doctor\AppDoctorIssueCode;
use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Models\App;
use App\Models\AppInstance;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private AppStateInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::App;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rows = App::query()
            ->whereHas('appInstances', static fn (Builder $query): Builder => $query
                ->where('node_id', $context->node->id)
                ->where('status', '!=', AppInstanceState::Removing))
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::App, 0, []);
        }
        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::App, $rows->count(), [new DoctorIssueData(
                AppDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'app',
                null,
                null,
                'App state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )]);
        }
        $issues = [];
        foreach ($rows as $app) {
            try {
                $observation = $this->inspector->inspect($app, $context->node);
                if (! $observation->repositoryOriginsMatch || $observation->failedInstanceIds !== []) {
                    $currentInstanceIds = $this->currentInstanceIds($app, $context->node->id);
                    $mismatchingInstanceIds = $observation->mismatchingInstanceIds === []
                        ? $currentInstanceIds
                        : array_intersect($observation->mismatchingInstanceIds, $currentInstanceIds);
                    $failedInstanceIds = array_intersect($observation->failedInstanceIds, $currentInstanceIds);

                    if (! $observation->repositoryOriginsMatch && $mismatchingInstanceIds !== []) {
                        $issues[] = new DoctorIssueData(
                            AppDoctorIssueCode::RepositoryOriginMismatch,
                            DoctorIssueKind::Drift,
                            'app',
                            $app->id,
                            $app->name,
                            'App repository origin does not match managed identity.',
                            'matching',
                            'mismatch',
                        );
                    }

                    if ($failedInstanceIds !== []) {
                        $issues[] = new DoctorIssueData(
                            AppDoctorIssueCode::InspectionFailed,
                            DoctorIssueKind::Unverifiable,
                            'app',
                            $app->id,
                            $app->name,
                            'App inspection could not be verified.',
                            'verifiable',
                            'unverifiable',
                        );
                    }
                }
            } catch (DoctorInspectionException) {
                if (! $this->hasCheckoutsOutsideRemoval($app, $context->node->id)) {
                    continue;
                }

                $issues[] = new DoctorIssueData(
                    AppDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'app',
                    $app->id,
                    $app->name,
                    'App inspection could not be verified.',
                    'verifiable',
                    'unverifiable',
                );
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::App, $rows->count(), $issues);
    }

    /** @return list<int> */
    private function currentInstanceIds(App $app, int $nodeId): array
    {
        $instances = AppInstance::query()
            ->where('app_id', $app->id)
            ->where('node_id', $nodeId)
            ->where('status', '!=', AppInstanceState::Removing)
            ->get(['id']);
        $ids = [];

        foreach ($instances as $instance) {
            $ids[] = $instance->id;
        }

        return $ids;
    }

    private function hasCheckoutsOutsideRemoval(App $app, int $nodeId): bool
    {
        return $this->currentInstanceIds($app, $nodeId) !== [];
    }
}
