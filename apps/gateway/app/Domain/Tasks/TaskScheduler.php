<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

final readonly class TaskScheduler
{
    public function __construct(
        private TaskConcurrencyGuard $ceilings,
        private InstanceProvisioning $provisioning,
        private AgentSpawner $spawner,
    ) {}

    public function claimNext(): ?TaskGroup
    {
        return DB::transaction(function (): ?TaskGroup {
            $candidates = TaskGroup::query()
                ->with(['tasks', 'taskable'])
                ->where('status', TaskGroupStatus::Queued)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $group) {
                if (! $this->ceilings->canActivate($group)) {
                    continue;
                }

                $group->status = TaskGroupStatus::Reserved;
                $group->save();

                $instance = $this->provisioning->provision(new InstanceProvisionIntent(
                    group: $group,
                    visitable: $this->visitable($group),
                ));

                if ($instance instanceof AppInstance) {
                    $group->taskable()->associate($instance);
                    $group->load('taskable');

                    if (! $this->ceilings->canActivate($group)) {
                        $group->save();

                        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
                    }

                    $this->start($group);
                }

                $group->save();

                return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
            }

            return null;
        });
    }

    private function start(TaskGroup $group): void
    {
        $group->status = TaskGroupStatus::Running;
        $reviewerThreadId = $this->spawner->spawnReviewer($group);

        if (is_string($reviewerThreadId) && $reviewerThreadId !== '') {
            $group->reviewer_thread_id = $reviewerThreadId;
        }

        $first = $group->tasks
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id])
            ->first();

        if (! $first instanceof Task) {
            return;
        }

        $first->status = TaskStatus::Running;
        $implementerThreadId = $this->spawner->spawnImplementer($first);

        if (is_string($implementerThreadId) && $implementerThreadId !== '') {
            $first->implementer_thread_id = $implementerThreadId;
        }

        $first->save();
    }

    private function visitable(TaskGroup $group): bool
    {
        $group->loadMissing('app');

        return $group->app->slug !== 'orbit';
    }
}
