<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Data\AppInstances\AppInstanceDeploymentData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\AppInstance;
use App\Models\AppInstanceDeployment;
use Illuminate\Support\Carbon;

/**
 * Records one deployments row per `instance:deploy` or `instance:rollback` run:
 * one write when the run starts, and one write when it finishes. Retains only
 * the most recent rows per AppInstance. Each write broadcasts the row, so a
 * connected client refreshes the Instance's deployment history without polling.
 */
final readonly class AppInstanceDeploymentRecorder
{
    public const int RETAINED_PER_INSTANCE = 50;

    public function __construct(
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function start(AppInstance $instance, string $triggeredBy): AppInstanceDeployment
    {
        $deployment = AppInstanceDeployment::query()->create([
            'app_instance_id' => $instance->id,
            'branch' => $instance->deployment_branch ?? $instance->branch,
            'started_at' => Carbon::now(),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
        ]);

        $this->broadcast(RecordEventType::DeploymentCreated, $deployment);

        return $deployment;
    }

    /** @param list<array<string, mixed>> $events */
    public function finish(AppInstanceDeployment $deployment, DeploymentResult $result, array $events): void
    {
        $startedAt = $deployment->started_at;
        $finishedAt = Carbon::now();

        $deployment->update([
            'release' => $result->release?->name,
            'commit' => $result->release?->commit,
            'status' => $result->succeeded ? 'succeeded' : 'failed',
            'failed_step' => $result->failure?->boundary->value,
            'error_code' => $result->failure?->errorCode,
            'selected_release' => $result->selectedRelease?->name,
            'finished_at' => $finishedAt,
            'duration_seconds' => max(0, $finishedAt->diffInSeconds($startedAt)),
            'events' => $events,
        ]);

        $this->prune($deployment->app_instance_id);
        $this->broadcast(RecordEventType::DeploymentUpdated, $deployment);
    }

    private function broadcast(RecordEventType $type, AppInstanceDeployment $deployment): void
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            $type,
            $deployment->id,
            AppInstanceDeploymentData::fromModel($deployment)->toArray(),
        );
    }

    private function prune(int $appInstanceId): void
    {
        $total = AppInstanceDeployment::query()->where('app_instance_id', $appInstanceId)->count();

        if ($total <= self::RETAINED_PER_INSTANCE) {
            return;
        }

        $staleIds = AppInstanceDeployment::query()
            ->where('app_instance_id', $appInstanceId)
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($total - self::RETAINED_PER_INSTANCE)
            ->pluck('id');

        AppInstanceDeployment::query()->whereIn('id', $staleIds)->delete();
    }
}
