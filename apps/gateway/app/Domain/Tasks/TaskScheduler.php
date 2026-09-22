<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            $tasks = $group->tasks
                ->filter(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true))
                ->sortBy(static fn (Task $task): array => [$task->position, $task->id]);

            foreach ($tasks as $task) {
                $task = $task->fresh();

                if (! $task instanceof Task || ! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
                    continue;
                }

                $group = $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
                $observation = $this->observer->observe($group, $task);

                if ($observation->threads === [] && $observation->available) {
                    $this->clearUnavailable($group);

                    continue;
                }

                if ($task->status === TaskStatus::Running && $this->handleImplementerCompletion($group, $task, $observation)) {
                    continue;
                }

                try {
                    $decision = ! $observation->available
                        ? $this->unavailableDecision($group)
                        : $this->classifyAvailable($group, $observation);
                } catch (TaskSessionClassificationException $exception) {
                    $decision = TaskSessionDecision::escalate($exception->getMessage());
                }

                try {
                    $this->actor->execute($group, $observation, $decision);
                    $this->advance($group, $task, $decision);
                } catch (AgentDriverException $exception) {
                    $decision = TaskSessionDecision::escalate($exception->getMessage());
                    $this->actor->execute($group, $observation, $decision);
                }

                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    private function handleImplementerCompletion(TaskGroup $group, Task $task, TaskSessionObservation $observation): bool
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null || ! $implementer->idle) {
            return false;
        }

        $comment = $task->comments()->where('type', 'ready_for_review')->latest('posted_at')->first();
        $mentionsComposerCheck = array_any($implementer->recentMessages, static fn (array $message): bool => str_contains(strtolower($message['text']), 'composer check'));
        if ($comment === null && ! $mentionsComposerCheck) {
            return false;
        }
        $validEvidence = array_any($implementer->recentMessages, static function (array $message): bool {
            $text = strtolower($message['text'].' '.$message['label']);

            return $message['kind'] === 'activity'
                && $message['label'] !== 'assistant'
                && str_contains($text, 'composer check')
                && (str_contains($text, 'passed') || str_contains($text, 'exit code 0') || str_contains($text, 'code 0'));
        });

        if ($comment !== null && $validEvidence && $task->completion_handoff_comment_id !== $comment->id) {
            $task->completion_handoff_comment_id = $comment->id;
            $task->save();
            $this->settleImplementer($task);

            return true;
        }

        if ($task->completion_reminder_attempt !== $task->completion_attempt) {
            $this->actor->remindCompletion($group, $implementer);
            $task->completion_reminder_attempt = $task->completion_attempt;
            $task->save();
        }

        return true;
    }

    private function classifyAvailable(TaskGroup $group, TaskSessionObservation $observation): TaskSessionDecision
    {
        $this->clearUnavailable($group);

        return $this->classifier->classify($observation);
    }

    private function clearUnavailable(TaskGroup $group): void
    {
        TaskGroup::query()->whereKey($group->id)->whereNotNull('agent_unavailable_since')->update([
            'agent_unavailable_since' => null, 'agent_unavailable_notified_at' => null,
        ]);
    }

    private function unavailableDecision(TaskGroup $group): TaskSessionDecision
    {
        TaskGroup::query()->whereKey($group->id)->whereNull('agent_unavailable_since')->update(['agent_unavailable_since' => now()]);
        $group->refresh();
        $grace = max(0, (int) config('orbit.tasks.observation_grace_seconds', 120));
        if ($group->agent_unavailable_since !== null && $group->agent_unavailable_since->lte(now()->subSeconds($grace))) {
            $claimed = TaskGroup::query()->whereKey($group->id)
                ->where('agent_unavailable_since', $group->agent_unavailable_since)
                ->whereNull('agent_unavailable_notified_at')
                ->update(['agent_unavailable_notified_at' => now()]);
            if ($claimed === 1) {
                return TaskSessionDecision::escalate('Agent observation unavailable beyond the grace period.');
            }
        }

        return new TaskSessionDecision(TaskSessionNextAction::Noop, 1.0, 'Waiting for an available agent observation.');
    }

    private function advance(TaskGroup $group, Task $task, TaskSessionDecision $decision): void
    {
        $group = $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        $current = $task->fresh();

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
        $reviewerAgentThreadId = $this->spawner->spawnReviewer($group);

        if ($reviewerAgentThreadId === null) {
            $this->failSpawn($group, null, 'reviewer');

            return;
        }

        $group->reviewer_agent_thread_id = $reviewerAgentThreadId;
        $group->save();

        $first = $this->orderedTasks($group->tasks)->first();

        if (! $first instanceof Task || $first->status !== TaskStatus::Pending) {
            return;
        }

        try {
            $this->startTask($first);
        } catch (TaskSequenceException) {
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

        if ($threadId === null) {
            $group = $task->taskGroup()->first();

            $this->failSpawn($group instanceof TaskGroup ? $group : null, $task, 'implementer');

            return;
        }

        $task->implementer_agent_thread_id = $threadId;
        $task->save();
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

    private function failSpawn(?TaskGroup $group, ?Task $task, string $agent): void
    {
        if ($task instanceof Task) {
            $task->status = TaskStatus::Failed;
            $task->save();
        }

        if ($group instanceof TaskGroup) {
            $group->status = TaskGroupStatus::Failed;
            $group->save();
        }

        Log::error('A task group agent spawn returned no thread id.', [
            'task_group_id' => $group?->id,
            'task_id' => $task?->id,
            'agent' => $agent,
        ]);
    }

    private function visitable(TaskGroup $group): bool
    {
        $group->loadMissing('app');

        return $group->app->slug !== 'orbit';
    }
}
