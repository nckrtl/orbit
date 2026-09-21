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
        private TaskPullRequestOpener $pullRequests,
        private TaskSettleMetricsCollector $metrics,
        private CoderSettleNotifier $coder,
        private TaskSessionObserver $observer,
    ) {}

    /**
     * Reads the current state of the group's task threads and shared
     * workspace. A tick observes before it decides anything.
     */
    public function observe(TaskGroup $group): TaskSessionObservation
    {
        return $this->observer->observe($group);
    }

    public function claimNext(): ?TaskGroup
    {
        $reserved = DB::transaction(function (): ?TaskGroup {
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

                return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
            }

            return null;
        });

        if (! $reserved instanceof TaskGroup) {
            return null;
        }

        $instance = $this->provisioning->provision(new InstanceProvisionIntent(
            group: $reserved,
            visitable: $this->visitable($reserved),
        ));

        if (! $instance instanceof AppInstance) {
            return $reserved->fresh(['tasks', 'app', 'taskable']) ?? $reserved;
        }

        $started = DB::transaction(function () use ($reserved, $instance): TaskGroup {
            $group = TaskGroup::query()
                ->with(['tasks', 'app', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($reserved->id);

            $group->taskable()->associate($instance);
            $group->load('taskable');

            if (! $this->ceilings->canActivate($group)) {
                $group->save();

                return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
            }

            $group->status = TaskGroupStatus::Running;
            $group->started_at ??= now();
            $group->save();

            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        });

        if ($started->status === TaskGroupStatus::Running) {
            $this->spawnOpeningAgents($started);
        }

        return $started->fresh(['tasks', 'app', 'taskable']) ?? $started;
    }

    public function settleImplementer(Task $task): TaskGroup
    {
        $group = DB::transaction(function () use ($task): TaskGroup {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = TaskGroup::query()
                ->with(['tasks', 'app', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($locked->task_group_id);

            if ($group->status !== TaskGroupStatus::Running || $locked->status !== TaskStatus::Running) {
                return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
            }

            $locked->status = TaskStatus::Reviewing;
            $locked->save();
            $group->status = TaskGroupStatus::Reviewing;
            $group->save();

            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        });

        $reviewing = $group->tasks->first(
            static fn (Task $candidate): bool => $candidate->id === $task->id,
        );

        if ($reviewing instanceof Task && $reviewing->status === TaskStatus::Reviewing) {
            $this->spawner->requestReview($reviewing);
        }

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    public function acceptReview(Task $task): TaskGroup
    {
        $this->spawner->signOff($task);

        /** @var Task|null $next */
        $next = null;
        $group = DB::transaction(function () use ($task, &$next): TaskGroup {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = TaskGroup::query()
                ->with(['tasks', 'app', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($locked->task_group_id);

            if ($group->status !== TaskGroupStatus::Reviewing || $locked->status !== TaskStatus::Reviewing) {
                return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
            }

            $locked->status = TaskStatus::Completed;
            $locked->settled_at ??= now();
            $locked->save();

            $next = $group->tasks
                ->sortBy(static fn (Task $candidate): array => [$candidate->position, $candidate->id])
                ->first(static fn (Task $candidate): bool => $candidate->status === TaskStatus::Pending);

            if ($next instanceof Task) {
                $next->status = TaskStatus::Running;
                $next->started_at ??= now();
                $next->save();
                $group->status = TaskGroupStatus::Running;
            } else {
                $group->status = TaskGroupStatus::Settling;
            }

            $group->save();

            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        });

        if ($next instanceof Task && $next->status === TaskStatus::Running) {
            $threadId = $this->spawner->spawnImplementer($next->fresh() ?? $next);

            if (is_string($threadId) && $threadId !== '') {
                $next->implementer_thread_id = $threadId;
                $next->save();
            }
        }

        if ($group->status === TaskGroupStatus::Settling) {
            return $this->settle($group);
        }

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    public function settle(TaskGroup $group): TaskGroup
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);

        if ($group->status !== TaskGroupStatus::Settling) {
            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        }

        $url = $group->pr_url;

        if (! is_string($url) || $url === '') {
            $opened = $this->pullRequests->open($group);

            if (is_string($opened) && $opened !== '') {
                $group->pr_url = $opened;
            }
        }

        $metrics = $this->metrics->collect($group);
        $group->tokens = $metrics->tokens;
        $group->line_diff = $metrics->lineDiff;
        $group->duration_ms = $metrics->durationMs;
        $group->settled_at ??= now();
        $group->save();

        $settled = $group->fresh(['tasks', 'app', 'taskable']) ?? $group;

        if ($settled->notify_coder) {
            $this->coder->notify($settled);
        }

        return $settled->fresh(['tasks', 'app', 'taskable']) ?? $settled;
    }

    private function spawnOpeningAgents(TaskGroup $group): void
    {
        $reviewerThreadId = $this->spawner->spawnReviewer($group);

        if (is_string($reviewerThreadId) && $reviewerThreadId !== '') {
            $group->reviewer_thread_id = $reviewerThreadId;
            $group->save();
        }

        $first = $group->tasks
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id])
            ->first();

        if (! $first instanceof Task) {
            return;
        }

        $first->status = TaskStatus::Running;
        $first->started_at ??= now();
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
