<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Models\AppInstance;
use App\Models\AppInstanceDeployment;
use Illuminate\Support\Carbon;

/**
 * Records one deployments row per `instance:deploy` or `instance:rollback` run:
 * one write when the run starts, and one write when it finishes. Retains only
 * the most recent rows per AppInstance.
 */
final readonly class AppInstanceDeploymentRecorder
{
    public const int RETAINED_PER_INSTANCE = 50;

    public function start(AppInstance $instance, string $triggeredBy): AppInstanceDeployment
    {
        return AppInstanceDeployment::query()->create([
            'app_instance_id' => $instance->id,
            'branch' => $instance->deployment_branch ?? $instance->branch,
            'started_at' => Carbon::now(),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
        ]);
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
