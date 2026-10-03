<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Data\Instances\InstanceDeploymentData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Support\Carbon;

/**
 * Records one deployments row per `instance:deploy` or `instance:rollback` run:
 * one write when the run starts, and one write when it finishes. Retains only
 * the most recent rows per Instance. Each write broadcasts the row, so a
 * connected client refreshes the Instance's deployment history without polling.
 */
final readonly class InstanceDeploymentRecorder
{
    public const int RETAINED_PER_INSTANCE = 50;

    public function __construct(
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function start(Instance $instance, string $triggeredBy): InstanceDeployment
    {
        $deployment = InstanceDeployment::query()->create([
            'instance_id' => $instance->id,
            'branch' => $instance->placedOnAppDev() && $instance->name === 'default'
                ? $instance->project->default_branch
                : ($instance->deployment_branch ?? $instance->branch),
            'started_at' => Carbon::now(),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
        ]);

        $this->broadcast(RecordEventType::DeploymentCreated, $deployment);

        return $deployment;
    }

    /** @param list<array<string, mixed>> $events */
    public function finish(InstanceDeployment $deployment, DeploymentResult $result, array $events): void
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

        $this->prune($deployment->instance_id);
        $this->broadcast(RecordEventType::DeploymentUpdated, $deployment);
    }

    private function broadcast(RecordEventType $type, InstanceDeployment $deployment): void
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            $type,
            $deployment->id,
            InstanceDeploymentData::fromModel($deployment)->toArray(),
        );
    }

    private function prune(int $instanceId): void
    {
        $total = InstanceDeployment::query()->where('instance_id', $instanceId)->count();

        if ($total <= self::RETAINED_PER_INSTANCE) {
            return;
        }

        $staleIds = InstanceDeployment::query()
            ->where('instance_id', $instanceId)
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($total - self::RETAINED_PER_INSTANCE)
            ->pluck('id');

        InstanceDeployment::query()->whereIn('id', $staleIds)->delete();
    }
}
