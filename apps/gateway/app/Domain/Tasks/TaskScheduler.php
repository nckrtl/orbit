<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Collection;
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
        private TaskExtensionState $extension,
        private TaskSessionObserver $observer,
        private TaskSessionClassifier $classifier,
        private TaskSessionActor $actor,
    ) {}

    /**
     * @return list<TaskSessionDecision>
     */
    public function tick(): array
    {
        if (! $this->extension->enabled()) {
            return [];
        }

        $groups = TaskGroup::query()
            ->with(['app', 'tasks', 'taskable'])
            ->whereIn('status', [TaskGroupStatus::Running, TaskGroupStatus::Reviewing])
            ->orderBy('id')
            ->get();

        $decisions = [];

        foreach ($groups as $group) {
            $observation = $this->observer->observe($group);

            if ($observation->threads === []) {
                continue;
            }

            try {
                $decision = $this->classifier->classify($observation);
            } catch (TaskSessionClassificationException $exception) {
                $decision = TaskSessionDecision::escalate($exception->getMessage());
            }

            try {
                $this->actor->execute($group, $observation, $decision);
            } catch (T3DispatchException $exception) {
                $decision = TaskSessionDecision::escalate($exception->getMessage());
                $this->actor->execute($group, $observation, $decision);
            }

            $this->advance($group, $decision);
            $decisions[] = $decision;
        }

        return $decisions;
    }

    private function advance(TaskGroup $group, TaskSessionDecision $decision): void
    {
        $group = $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        $current = $group->tasks
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id])
            ->first(static fn (Task $task): bool => in_array($task->status, [
                TaskStatus::Running,
                TaskStatus::Reviewing,
            ], true));

        if ($decision->action === TaskSessionNextAction::MarkSubtaskDone && $current instanceof Task) {
            if ($current->status === TaskStatus::Running) {
                $this->settleImplementer($current);
            } elseif ($current->status === TaskStatus::Reviewing) {
                $this->acceptReview($current);
            }

            return;
        }

        if ($decision->action !== TaskSessionNextAction::SettleGroup) {
            return;
        }

        if ($current instanceof Task && $current->status === TaskStatus::Reviewing) {
            $this->acceptReview($current);

            return;
        }

        if ($group->status === TaskGroupStatus::Settling) {
            $this->settle($group);
        }
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

    public function startTask(Task $task): TaskGroup
    {
        $started = $this->activateRunningTask($task);
        $this->assignImplementer($started);

        $group = $started->taskGroup;

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

            $tasks = $this->lockedTasks($group);
            $next = $this->lowestPending($tasks);

            if ($next instanceof Task) {
                try {
                    $this->markRunning($next, $tasks);
                    $group->status = TaskGroupStatus::Running;
                } catch (TaskSequenceException) {
                    $next = null;
                    $group->status = $this->runningSibling($tasks) instanceof Task
                        ? TaskGroupStatus::Running
                        : TaskGroupStatus::Reviewing;
                }
            } else {
                $group->status = TaskGroupStatus::Settling;
            }

            $group->save();

            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        });

        if ($next instanceof Task && $next->status === TaskStatus::Running) {
            $this->assignImplementer($next);
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

        if (! is_string($reviewerThreadId) || $reviewerThreadId === '') {
            $this->failOpeningSpawn($group, null, 'reviewer');
        }

        $group->reviewer_thread_id = $reviewerThreadId;
        $group->save();

        $first = $this->orderedTasks($group->tasks)->first();

        if (! $first instanceof Task || $first->status !== TaskStatus::Pending) {
            return;
        }

        try {
            $this->startTask($first);
        } catch (TaskSequenceException) {
        }

        if (! is_string($first->implementer_thread_id) || $first->implementer_thread_id === '') {
            $this->failOpeningSpawn($group, $first, 'implementer');
        }
    }

    /**
     * @throws TaskSequenceException
     */
    private function activateRunningTask(Task $task): Task
    {
        return DB::transaction(function () use ($task): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = TaskGroup::query()
                ->lockForUpdate()
                ->findOrFail($locked->task_group_id);
            $tasks = $this->lockedTasks($group);

            $this->markRunning($locked, $tasks);

            return $locked->fresh(['taskGroup.tasks', 'taskGroup.app', 'taskGroup.taskable']) ?? $locked;
        });
    }

    /**
     * @param  Collection<int, Task>  $tasks
     *
     * @throws TaskSequenceException
     */
    private function markRunning(Task $task, Collection $tasks): void
    {
        $running = $this->runningSibling($tasks, $task);

        if ($running instanceof Task) {
            throw TaskSequenceException::siblingRunning($task->task_group_id, $running->id);
        }

        $next = $this->lowestPending($tasks);

        if (! $next instanceof Task || $next->id !== $task->id || ! $this->predecessorsCompleted($task, $tasks)) {
            throw TaskSequenceException::notNext($task->id, $task->task_group_id);
        }

        $task->status = TaskStatus::Running;
        $task->started_at ??= now();
        $task->save();
    }

    private function assignImplementer(Task $task): void
    {
        if ($task->status !== TaskStatus::Running) {
            return;
        }

        $threadId = $this->spawner->spawnImplementer($task->fresh() ?? $task);

        if (is_string($threadId) && $threadId !== '') {
            $task->implementer_thread_id = $threadId;
            $task->save();
        }
    }

    /** @return Collection<int, Task> */
    private function lockedTasks(TaskGroup $group): Collection
    {
        $tasks = Task::query()
            ->where('task_group_id', $group->id)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $group->setRelation('tasks', $tasks);

        return $tasks;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    private function orderedTasks(Collection $tasks): Collection
    {
        return $tasks
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id])
            ->values();
    }

    /** @param  Collection<int, Task>  $tasks */
    private function lowestPending(Collection $tasks): ?Task
    {
        return $this->orderedTasks($tasks)->first(
            static fn (Task $task): bool => $task->status === TaskStatus::Pending,
        );
    }

    /** @param  Collection<int, Task>  $tasks */
    private function runningSibling(Collection $tasks, ?Task $except = null): ?Task
    {
        return $this->orderedTasks($tasks)->first(
            static fn (Task $task): bool => $task->status === TaskStatus::Running
                && ($except === null || $task->id !== $except->id),
        );
    }

    /** @param  Collection<int, Task>  $tasks */
    private function predecessorsCompleted(Task $task, Collection $tasks): bool
    {
        return $this->orderedTasks($tasks)
            ->filter(static fn (Task $candidate): bool => $candidate->position < $task->position
                || ($candidate->position === $task->position && $candidate->id < $task->id))
            ->every(static fn (Task $candidate): bool => $candidate->status === TaskStatus::Completed);
    }

    private function failOpeningSpawn(TaskGroup $group, ?Task $task, string $agent): never
    {
        if ($task instanceof Task) {
            $task->status = TaskStatus::Failed;
            $task->save();
        }

        $group->status = TaskGroupStatus::Failed;
        $group->save();

        throw new \RuntimeException("Task group {$group->id} could not start: {$agent} spawn returned no thread id.");
    }

    private function visitable(TaskGroup $group): bool
    {
        $group->loadMissing('app');

        return $group->app->slug !== 'orbit';
    }
}
