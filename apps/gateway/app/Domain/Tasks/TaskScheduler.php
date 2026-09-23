<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TaskScheduler
{
    public function __construct(
        private TaskConcurrencyGuard $ceilings,
        private InstanceProvisioning $provisioning,
        private AgentSpawner $spawner,
        private TaskSettleMetricsCollector $metrics,
        private TaskWorkspaceDiffReader $diff,
        private TaskWorkspaceStateReader $workspace,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private CompleteTaskGroupAction $completeGroup,
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

        $groups = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
            ->with(['app', 'tasks', 'taskable'])
            ->whereIn('status', [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling])
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            if ($group->status !== TaskGroupStatus::Settling) {
                continue;
            }
            if (! is_string($group->pr_url) || $group->pr_url === '') {
                $this->requestMissingPullRequest($group);

                continue;
            }
            $status = $this->pullRequestWatcher->status($group);
            if ($status === 'merged') {
                try {
                    $this->completeGroup->execute($group);
                } catch (Throwable $exception) {
                    $group->update(['assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: '.$exception->getMessage()]);
                }
            } elseif ($status === 'closed') {
                $group->update(['assistance_requested' => true, 'assistance_reason' => 'The expected pull request closed without merging.']);
            }
        }

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
                if ($task->assistance_requested || $group->assistance_requested) {
                    continue;
                }

                $group = $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
                $observation = $this->observer->observe($group, $task);

                if ($observation->available) {
                    $this->clearUnavailable($group);
                }
                if (($observation->available && $observation->threads === []) || ($task->status === TaskStatus::Running && $observation->thread(TaskThreadRole::Implementer) === null)) {
                    continue;
                }

                if ($observation->available && $task->status === TaskStatus::Running && $this->handleImplementerCompletion($group, $task, $observation)) {
                    continue;
                }

                if ($observation->available && $task->status === TaskStatus::Reviewing && $this->handleReviewerOutcome($group, $task, $observation)) {
                    continue;
                }

                try {
                    $decision = ! $observation->available
                        ? $this->unavailableDecision($group)
                        : $this->classifyAvailable($group, $observation);
                } catch (TaskSessionClassificationException $exception) {
                    $decision = TaskSessionDecision::escalate($exception->getMessage());
                }

                if ($decision->action === TaskSessionNextAction::EscalateCoder) {
                    if (! $observation->available) {
                        $this->actor->execute($group, $observation, $decision);
                        $decisions[] = $decision;

                        continue;
                    }
                    $this->requestAssistance($task, $group, $decision->reason, $observation);
                    $decisions[] = $decision;

                    continue;
                }

                try {
                    $this->actor->execute($group, $observation, $decision);
                    $this->advance($group, $task, $decision);
                    if ($task->communication_failures > 0) {
                        $task->update(['communication_failures' => 0]);
                    }
                } catch (AgentDriverException $exception) {
                    $decision = TaskSessionDecision::escalate($exception->getMessage());
                    $task->increment('communication_failures');
                    $task->refresh();
                    if ($task->communication_failures >= 5) {
                        $task->update(['assistance_requested' => true, 'assistance_reason' => $exception->getMessage()]);
                        $group->update(['assistance_requested' => true, 'assistance_reason' => $exception->getMessage()]);
                    }
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
        if ($implementer === null) {
            return false;
        }
        $state = AgentThreadState::tryFrom($implementer->sessState);
        if ($state === AgentThreadState::Failed) {
            $this->requestAssistance($task, $group, 'The implementer thread failed.', $observation);

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }

        if ($task->completion_handoff_attempt !== null && ! $this->newerTurnHasStopped($task->completion_handoff_turn_id, $implementer)) {
            return true;
        }

        try {
            $waiting = $this->waitingItem($implementer);
            $checks = $waiting instanceof TaskRubricItem
                ? []
                : $this->classifier->classifyTranscript($observation, TaskThreadRole::Implementer);
            $items = $this->implementerItems($group, $task, $implementer, $checks);
        } catch (TaskSessionClassificationException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        if ($this->failedItems($items) === []) {
            $this->settleImplementer($task, $observation->thread(TaskThreadRole::Reviewer)?->turnId);

            return true;
        }

        $this->remindOrAssist($group, $task, $implementer, $items, true);

        return true;
    }

    private function handleReviewerOutcome(TaskGroup $group, Task $task, TaskSessionObservation $observation): bool
    {
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        if ($reviewer === null) {
            return false;
        }
        $state = AgentThreadState::tryFrom($reviewer->sessState);
        if ($state === AgentThreadState::Failed) {
            $this->requestAssistance($task, $group, 'The reviewer thread failed.', $observation);

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }

        /** @var TaskComment|null $comment */
        $comment = $task->comments()
            ->whereIn('type', [TaskCommentType::ChangesRequested->value, TaskCommentType::Approved->value])
            ->where('review_attempt', $task->review_attempt)
            ->latest('posted_at')->latest('id')->first();
        $commentType = $comment === null ? null : TaskCommentType::tryFrom((string) $comment->getRawOriginal('type'));
        if ($comment instanceof TaskComment && $commentType === TaskCommentType::ChangesRequested) {
            if ($task->review_handled_comment_id === $comment->id) {
                return true;
            }
            $implementer = $observation->thread(TaskThreadRole::Implementer);
            if ($implementer === null) {
                $this->requestAssistance($task, $group, 'The implementer thread is unavailable for the review findings.', $observation);

                return true;
            }
            try {
                $this->actor->relayReviewBody($group, $implementer, $comment->body);
            } catch (AgentDriverException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
            $task->update([
                'status' => TaskStatus::Running,
                'review_handled_comment_id' => $comment->id,
                'review_attempt' => $task->review_attempt + 1,
                'review_reminder_attempt' => null,
                'review_reminder_input_id' => null,
                'completion_attempt' => $task->completion_attempt + 1,
                'completion_handoff_attempt' => $task->completion_attempt + 1,
                'completion_handoff_turn_id' => $implementer->turnId,
                'completion_handoff_check_id' => ComposerCheckEvidence::fromMessages($implementer->recentMessages)->runId,
                'communication_failures' => 0,
                'completion_handoff_comment_id' => null,
                'completion_reminder_attempt' => null,
                'completion_reminder_input_id' => null,
            ]);
            $group->update(['status' => TaskGroupStatus::Running]);

            return true;
        }

        if (! $comment instanceof TaskComment || $commentType !== TaskCommentType::Approved) {
            if ($task->review_notified_attempt !== $task->review_attempt) {
                $this->nudgeReviewer($task, $reviewer->turnId);

                return true;
            }
            if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
                return true;
            }
        }

        $items = [];
        if (! $comment instanceof TaskComment || $commentType !== TaskCommentType::Approved) {
            $items[] = new TaskRubricItem('outcome_comment', false, 'Post one changes_requested or approved comment for this review attempt.');
            $waiting = $this->waitingItem($reviewer);
            if (! $waiting instanceof TaskRubricItem) {
                try {
                    $checks = $this->classifier->classifyTranscript($observation, TaskThreadRole::Reviewer);
                } catch (TaskSessionClassificationException $exception) {
                    $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                    return true;
                }
                if (! isset($checks['blocked'])) {
                    $this->recordCommunicationFailure($task, $group, 'TypeSafe Jev did not return the blocked check.');

                    return true;
                }
                $items[] = $this->jevItem($checks['blocked'], 'no');
            }
        } else {
            array_push($items, ...$this->approvalItems($group, $task, $comment));
        }
        $waiting = $this->waitingItem($reviewer);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }
        if ($this->failedItems($items) === []) {
            if ($comment !== null && $commentType === TaskCommentType::Approved) {
                $isFinal = $this->isFinalSubtask($group, $task);
                if ($isFinal) {
                    $group->update(['pr_url' => $comment->pr_url]);
                }
                $task->update(['review_handled_comment_id' => $comment->id, 'communication_failures' => 0]);
                $this->acceptReview($task);
            }

            return true;
        }

        $this->remindOrAssist($group, $task, $reviewer, $items, false);

        return true;
    }

    /** @param array<string, TaskTranscriptCheck> $checks
     * @return list<TaskRubricItem>
     */
    private function implementerItems(TaskGroup $group, Task $task, TaskThreadObservation $thread, array $checks): array
    {
        $evidence = ComposerCheckEvidence::fromMessages($thread->recentMessages);
        $freshRun = $task->completion_handoff_attempt === null
            || ($evidence->runId !== null && $evidence->runId !== $task->completion_handoff_check_id);
        $instance = $group->taskable;
        $items = [
            new TaskRubricItem('check_script', $instance instanceof AppInstance && $this->workspace->definesComposerCheckScript($instance), 'composer.json in the workspace does not define a check script, so composer check ran a built-in Composer command. Restore the check script and run composer check again.'),
            new TaskRubricItem('check_invoked', $evidence->invoked, 'composer check was not found in the recent tool output. Run composer check.'),
            new TaskRubricItem('check_passed', $evidence->invoked && $evidence->passed, 'composer check did not pass. Run composer check again.'),
            new TaskRubricItem('check_current', $evidence->invoked && $evidence->passed && $evidence->current && $freshRun, 'composer check output is from before a change to the tree or the latest review findings. Run composer check again.'),
        ];
        $waiting = $this->waitingItem($thread);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;

            return $items;
        }
        if (! isset($checks['blocked'])) {
            throw new TaskSessionClassificationException('TypeSafe Jev did not return the blocked check.');
        }
        $items[] = $this->jevItem($checks['blocked'], 'no');

        return $items;
    }

    /** @return list<TaskRubricItem> */
    private function approvalItems(TaskGroup $group, Task $task, TaskComment $comment): array
    {
        $instance = $group->taskable;
        $head = $instance instanceof AppInstance ? $this->workspace->headCommit($instance) : null;
        $sha = is_string($comment->commit_sha) ? $comment->commit_sha : '';
        $shaMatches = preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $sha) === 1
            && $instance instanceof AppInstance
            && $sha === $head;
        $items = [];
        if (! $shaMatches) {
            $items[] = new TaskRubricItem('commit_sha', false, 'commit_sha does not equal the workspace HEAD.');
        }
        if (! $instance instanceof AppInstance || $this->workspace->currentBranch($instance) !== 'task-'.$group->id) {
            $items[] = new TaskRubricItem('branch', false, 'the workspace branch is not task-'.$group->id.'.');
        }
        if (! $instance instanceof AppInstance || ! $this->workspace->isClean($instance)) {
            $items[] = new TaskRubricItem('working_tree', false, 'the working tree is not clean.');
        }
        $start = (string) $task->subtask_start_commit;
        if (! $instance instanceof AppInstance || $sha === $task->subtask_start_commit || ! $this->diff->hasCommitsSince($instance, $start)) {
            $items[] = new TaskRubricItem('new_commit', false, 'the commit is not new since this subtask started.');
        }
        if ($this->isFinalSubtask($group, $task) && (! is_string($comment->pr_url) || ! $this->pullRequestWatcher->verifies($group, $comment->pr_url, $sha))) {
            $items[] = new TaskRubricItem('pull_request', false, 'the pull request does not verify for the approved commit.');
        }

        return $items;
    }

    private function jevItem(TaskTranscriptCheck $check, string $passingChoice): TaskRubricItem
    {
        return new TaskRubricItem(
            $check->key,
            $check->choice === $passingChoice && $check->confidence >= $this->jevThreshold(),
            '',
            $check->choice,
            $check->confidence,
        );
    }

    private function waitingItem(TaskThreadObservation $thread): ?TaskRubricItem
    {
        $waiting = $thread->sessState === AgentThreadState::AskingForInput->value
            || $thread->pendingApprovalId !== null
            || $thread->pendingUserInputId !== null;
        if (! $waiting) {
            return null;
        }

        $work = $thread->role === TaskThreadRole::Implementer ? 'brief' : 'review';

        return new TaskRubricItem('waiting_for_input', false, 'The thread is waiting for input. Continue the current '.$work.' without it.');
    }

    /** @param list<TaskRubricItem> $items */
    private function remindOrAssist(TaskGroup $group, Task $task, TaskThreadObservation $thread, array $items, bool $implementer): void
    {
        $failures = $this->failedItems($items);
        $attempt = $implementer ? 'completion_attempt' : 'review_attempt';
        $reminder = $implementer ? 'completion_reminder_attempt' : 'review_reminder_attempt';
        $input = $implementer ? 'completion_reminder_input_id' : 'review_reminder_input_id';
        $pendingId = $thread->pendingUserInputId ?? $thread->pendingApprovalId;
        if ($task->{$reminder} === $task->{$attempt} && $pendingId !== null && $pendingId === $task->{$input}) {
            return;
        }
        if ($task->{$reminder} !== $task->{$attempt}) {
            try {
                $this->actor->remindRubric($group, $thread, TaskRubricReminder::compose($thread->role, $failures));
            } catch (AgentDriverException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            $task->update([$reminder => $task->{$attempt}, $input => $pendingId, 'communication_failures' => 0]);

            return;
        }

        $reasons = array_map(static function (TaskRubricItem $item): string {
            if ($item->choice !== null && $item->confidence !== null) {
                return trim($item->reminder.' '.$item->key.': Jev answered '.$item->choice.' at '.$item->confidence.'.');
            }

            return $item->reminder;
        }, $failures);
        $this->requestAssistance($task, $group, 'Checks still failed after the reminder. '.implode(' ', $reasons));
    }

    /** @param list<TaskRubricItem> $items
     * @return list<TaskRubricItem>
     */
    private function failedItems(array $items): array
    {
        return array_values(array_filter($items, static fn (TaskRubricItem $item): bool => ! $item->passed));
    }

    private function isFinalSubtask(TaskGroup $group, Task $task): bool
    {
        return ! $group->tasks()->whereIn('status', [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running])->where('id', '!=', $task->id)->exists();
    }

    private function jevThreshold(): float
    {
        $threshold = config('orbit.tasks.jev_confidence_threshold', 0.75);

        return is_numeric($threshold) ? (float) $threshold : 0.75;
    }

    private function clearCommunicationFailures(Task $task): void
    {
        if ($task->communication_failures > 0) {
            $task->update(['communication_failures' => 0]);
        }
    }

    private function recordCommunicationFailure(Task $task, TaskGroup $group, string $reason): void
    {
        $task->increment('communication_failures');
        $task->refresh();
        if ($task->communication_failures >= 5) {
            $this->requestAssistance($task, $group, $reason);
        }
    }

    private function requestAssistance(Task $task, TaskGroup $group, string $reason, ?TaskSessionObservation $observation = null): void
    {
        if ($task->assistance_requested || $group->assistance_requested) {
            return;
        }
        $task->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
        $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
        $this->coder->assistance($group, $reason);
    }

    private function classifyAvailable(TaskGroup $group, TaskSessionObservation $observation): TaskSessionDecision
    {
        $this->clearUnavailable($group);

        return new TaskSessionDecision(TaskSessionNextAction::Noop, 1.0, 'Waiting for a known agent state.');
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
            $candidates = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
                ->with(['tasks', 'taskable'])
                ->where('status', TaskGroupStatus::Todo)
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
            $reserved->update(['status' => TaskGroupStatus::Todo]);

            return null;
        }

        $started = DB::transaction(function () use ($reserved, $instance): TaskGroup {
            $group = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
                ->with(['tasks', 'app', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($reserved->id);

            $group->taskable()->associate($instance);
            $group->load('taskable');

            if (! $this->ceilings->canActivate($group)) {
                $group->status = TaskGroupStatus::Todo;
                $group->taskable()->dissociate();
                $group->save();

                return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
            }

            $group->status = TaskGroupStatus::Running;
            $group->started_at ??= now();
            $group->save();

            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        });

        if ($started->status !== TaskGroupStatus::Running) {
            return null;
        }

        $this->spawnOpeningAgents($started);

        return $started->fresh(['tasks', 'app', 'taskable']) ?? $started;
    }

    private function nudgeReviewer(Task $task, ?string $turnId): void
    {
        if ($task->review_notified_attempt === $task->review_attempt) {
            return;
        }

        try {
            $this->spawner->requestReview($task);
        } catch (AgentDriverException $exception) {
            $this->recordCommunicationFailure($task, $task->taskGroup, $exception->getMessage());

            return;
        }

        $task->update([
            'review_notified_attempt' => $task->review_attempt,
            'review_notified_turn_id' => $turnId,
        ]);
        $this->clearCommunicationFailures($task);
    }

    private function newerTurnHasStopped(?string $previousTurnId, TaskThreadObservation $thread): bool
    {
        return is_string($thread->turnId) && $thread->turnId !== ''
            && $thread->turnId !== $previousTurnId
            && in_array($thread->sessState, [AgentThreadState::Done->value, AgentThreadState::AskingForInput->value], true);
    }

    public function settleImplementer(Task $task, ?string $turnId = null): TaskGroup
    {
        $task->taskGroup->requireManagedExecution();
        $group = DB::transaction(function () use ($task): TaskGroup {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
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
            $this->nudgeReviewer($reviewing, $turnId);
        }

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    public function startTask(Task $task): TaskGroup
    {
        $task->taskGroup->requireManagedExecution();
        $started = $this->activateRunningTask($task);
        $this->recordSubtaskStart($started);
        $this->assignImplementer($started);

        $group = $started->taskGroup;

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    public function acceptReview(Task $task): TaskGroup
    {
        $task->taskGroup->requireManagedExecution();
        /** @var Task|null $next */
        $next = null;
        $group = DB::transaction(function () use ($task, &$next): TaskGroup {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
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
            $next = $this->lowestTodo($tasks);

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
            $this->recordSubtaskStart($next);
            $this->assignImplementer($next);
        }

        if ($group->status === TaskGroupStatus::Settling) {
            return $this->settle($group);
        }

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    public function settle(TaskGroup $group): TaskGroup
    {
        $group->requireManagedExecution();
        $group->loadMissing(['app', 'tasks', 'taskable']);

        if ($group->status !== TaskGroupStatus::Settling) {
            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        }

        $url = $group->pr_url;

        if (! is_string($url) || $url === '') {
            $this->requestMissingPullRequest($group);

            return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
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

    private function requestMissingPullRequest(TaskGroup $group): void
    {
        if ($group->assistance_requested) {
            return;
        }

        $reason = 'The settling group has no reviewed pull request URL.';
        $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
        $this->coder->assistance($group, $reason);
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

        if (! $first instanceof Task || $first->status !== TaskStatus::Todo) {
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
            $group = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
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

        $next = $this->lowestTodo($tasks);

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

    private function recordSubtaskStart(Task $task): void
    {
        $instance = $task->taskGroup()->with('taskable')->first()?->taskable;
        if ($instance instanceof AppInstance) {
            $task->update(['subtask_start_commit' => $this->workspace->headCommit($instance)]);
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
    private function lowestTodo(Collection $tasks): ?Task
    {
        return $this->orderedTasks($tasks)->first(
            static fn (Task $task): bool => $task->status === TaskStatus::Todo,
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
