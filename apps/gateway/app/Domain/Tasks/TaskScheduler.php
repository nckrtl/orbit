<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Carbon;
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
        private TaskWorkspaceStateReader $workspace,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private CompleteTaskGroupAction $completeGroup,
        private CoderSettleNotifier $coder,
        private TaskExtensionState $extension,
        private TaskSessionObserver $observer,
        private TaskSessionActor $actor,
        private TaskRunReceipts $receipts,
        private TaskWorkspaceSigner $signer,
        private TaskBriefCoverage $coverage,
        private TaskPullRequestPublisher $publisher,
        private TaskCheckRunner $checks,
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
            $read = $this->collectReceipt($group, $task, TaskThreadRole::Implementer);
        } catch (TaskRunReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Implementer);
        if ($receipt instanceof TaskComment && $this->receiptOutcome($receipt) === TaskRunOutcome::Blocked) {
            $task->update(['completion_handoff_comment_id' => $receipt->id]);
            $this->requestAssistance($task, $group, 'The implementer is blocked: '.$receipt->body, $observation);

            return true;
        }

        $items = $this->implementerItems($group, $task, $implementer, $read, $receipt);
        if ($this->failedItems($items) === [] && $receipt instanceof TaskComment) {
            $this->checkHandoff($group, $task, $implementer, $receipt, $observation);

            return true;
        }

        if ($this->remindOrAssist($group, $task, $implementer, $items) && $receipt instanceof TaskComment) {
            $task->update(['completion_handoff_comment_id' => $receipt->id]);
        }

        return true;
    }

    /**
     * Runs the Project check for a handoff and acts on its state. The process state decides; no timer ends a check.
     */
    private function checkHandoff(TaskGroup $group, Task $task, TaskThreadObservation $implementer, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }
        /** @var TaskCheck|null $check */
        $check = TaskCheck::query()->where('task_comment_id', $receipt->id)->latest('id')->first();
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running) {
            try {
                $reading = $this->checks->read($instance, $check->process());
            } catch (TaskCheckException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            if ($reading->state === 'running') {
                $this->clearCommunicationFailures($task);

                return;
            }
            $this->recordReading($check, $reading);
        }

        $status = $check?->status;
        if ($check instanceof TaskCheck && $status === TaskCheckStatus::Passed) {
            $task->update(['completion_handoff_comment_id' => $receipt->id, 'communication_failures' => 0]);
            $this->settleImplementer($task, $observation->thread(TaskThreadRole::Reviewer)?->turnId);

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_comment_id', $receipt->id)->where('status', $check->status->value)->count()
            : 0;
        $item = match (true) {
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed => new TaskRubricItem('check_passed', false, "Orbit ran composer check, and it failed with exit code {$check->exit_code}. The end of its output:\n\n```\n".rtrim((string) $check->output)."\n```\n"),
            $status === TaskCheckStatus::Cancelled => new TaskRubricItem('check_passed', false, "An operator cancelled Orbit's composer check before it finished."),
            $check instanceof TaskCheck && $status === TaskCheckStatus::Changed && $repeats >= 2 => new TaskRubricItem('check_passed', false, 'The workspace changed while composer check ran, twice. Changed paths: '.implode(', ', $check->changed_paths ?? []).'. Make the check leave the tree unchanged, for example by ignoring the files it writes.'),
            default => null,
        };
        if ($item instanceof TaskRubricItem) {
            if ($this->remindOrAssist($group, $task, $implementer, [$item])) {
                $task->update(['completion_handoff_comment_id' => $receipt->id]);
            }

            return;
        }
        if ($status === TaskCheckStatus::Lost && $repeats >= 2) {
            $task->update(['completion_handoff_comment_id' => $receipt->id]);
            $this->requestAssistance($task, $group, "Orbit's composer check stopped twice without a result.", $observation);

            return;
        }

        try {
            $process = $this->checks->start($instance);
        } catch (TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        TaskCheck::query()->create([
            'task_id' => $task->id,
            'task_comment_id' => $receipt->id,
            'status' => TaskCheckStatus::Running,
            'pid' => $process->pid,
            'process_started' => $process->started,
            'head_before' => $process->head,
            'tree_before' => $process->tree,
            'started_at' => now(),
        ]);
        $this->clearCommunicationFailures($task);
    }

    /**
     * Records a finished or lost check. The write applies only while the check is still running, so a cancel wins.
     */
    private function recordReading(TaskCheck $check, TaskCheckReading $reading): void
    {
        $changed = $reading->headAfter !== $check->head_before || $reading->treeAfter !== $check->tree_before;
        $values = $reading->state === 'lost'
            ? ['status' => TaskCheckStatus::Lost->value, 'output' => $reading->output]
            : [
                'status' => match (true) {
                    $changed => TaskCheckStatus::Changed->value,
                    $reading->exitCode === 0 => TaskCheckStatus::Passed->value,
                    default => TaskCheckStatus::Failed->value,
                },
                'head_after' => $reading->headAfter,
                'tree_after' => $reading->treeAfter,
                'exit_code' => $reading->exitCode,
                'changed_paths' => json_encode($reading->changedPaths, JSON_THROW_ON_ERROR),
                'output' => $reading->output,
            ];
        $finishedAt = $reading->finishedAt === null ? now() : Carbon::createFromTimestamp($reading->finishedAt);
        TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running->value)
            ->update([...$values, 'finished_at' => $finishedAt, 'updated_at' => now()]);
        $check->refresh();
    }

    private function handleReviewerOutcome(TaskGroup $group, Task $task, TaskSessionObservation $observation): bool
    {
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        if ($reviewer === null || $task->review_notified_attempt !== $task->review_attempt) {
            $this->nudgeReviewer($task, $reviewer?->turnId);

            return true;
        }
        $state = AgentThreadState::tryFrom($reviewer->sessState);
        if ($state === AgentThreadState::Failed) {
            $this->requestAssistance($task, $group, 'The reviewer thread failed.', $observation);

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }
        if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return true;
        }

        try {
            $read = $this->collectReceipt($group, $task, TaskThreadRole::Reviewer);
        } catch (TaskRunReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        $outcome = $receipt instanceof TaskComment ? $this->receiptOutcome($receipt) : null;
        if ($receipt instanceof TaskComment && $outcome === TaskRunOutcome::Blocked) {
            $task->update(['review_handled_comment_id' => $receipt->id]);
            $this->requestAssistance($task, $group, 'The reviewer is blocked: '.$receipt->body, $observation);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskRunOutcome::ChangesRequested) {
            $this->relayFindings($group, $task, $observation, $receipt);

            return true;
        }

        $instance = $group->taskable;
        $pullRequest = null;
        $items = [$this->receiptItem($read, $receipt)];
        if ($receipt instanceof TaskComment && $outcome === TaskRunOutcome::Approved) {
            $onBranch = $instance instanceof AppInstance && $this->workspace->currentBranch($instance) === 'task-'.$group->id;
            $items[] = new TaskRubricItem('branch', $onBranch, 'The workspace branch is not task-'.$group->id.'. Switch back to it.');
            if ($task->isLastSubtask()) {
                $pullRequest = TaskRunPullRequest::fromArray($receipt->pull_request);
                $items[] = new TaskRubricItem('pull_request_fields', $pullRequest instanceof TaskRunPullRequest, 'The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking.');
            }
        }
        $waiting = $this->waitingItem($reviewer);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }
        if ($this->failedItems($items) === [] && $pullRequest instanceof TaskRunPullRequest) {
            try {
                $missing = $this->coverage->missing($group, $pullRequest);
            } catch (TaskSessionClassificationException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
            foreach ($missing as $title) {
                $items[] = new TaskRubricItem('brief_coverage', false, 'The pull request change list does not cover the subtask "'.$title.'".');
            }
        }
        if (! $receipt instanceof TaskComment || $this->failedItems($items) !== []) {
            if ($this->remindOrAssist($group, $task, $reviewer, $items) && $receipt instanceof TaskComment) {
                $task->update(['review_handled_comment_id' => $receipt->id]);
            }

            return true;
        }

        $commit = $instance instanceof AppInstance ? $this->signer->commit($instance, $task->title."\n\n".$receipt->body) : null;
        if ($commit === null) {
            $this->recordCommunicationFailure($task, $group, 'Orbit could not commit the approved subtask.');

            return true;
        }
        $receipt->update(['commit_sha' => $commit]);
        if ($pullRequest instanceof TaskRunPullRequest) {
            try {
                $url = $this->publisher->publish($group, TaskPullRequestDescription::render($pullRequest, $group->tasks()->count()));
            } catch (TaskPullRequestException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
            $group->update(['pr_url' => $url]);
        }
        $task->update(['review_handled_comment_id' => $receipt->id, 'communication_failures' => 0]);
        $this->acceptReview($task);

        return true;
    }

    private function relayFindings(TaskGroup $group, Task $task, TaskSessionObservation $observation, TaskComment $findings): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            $this->requestAssistance($task, $group, 'The implementer thread is unavailable for the review findings.', $observation);

            return;
        }
        try {
            $this->prepareTurn($group, $task, TaskThreadRole::Implementer);
            $this->actor->relayReviewBody($group, $implementer, $findings->body);
        } catch (AgentDriverException|TaskRunReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $task->update([
            'status' => TaskStatus::Running,
            'review_handled_comment_id' => $findings->id,
            'review_attempt' => $task->review_attempt + 1,
            'review_reminder_attempt' => null,
            'review_reminder_input_id' => null,
            'completion_attempt' => $task->completion_attempt + 1,
            'completion_handoff_attempt' => $task->completion_attempt + 1,
            'completion_handoff_turn_id' => $implementer->turnId,
            'communication_failures' => 0,
            'completion_handoff_comment_id' => null,
            'completion_reminder_attempt' => null,
            'completion_reminder_input_id' => null,
        ]);
        $group->update(['status' => TaskGroupStatus::Running]);
    }

    /** @return list<TaskRubricItem> */
    private function implementerItems(TaskGroup $group, Task $task, TaskThreadObservation $thread, ?TaskRunReceipt $read, ?TaskComment $receipt): array
    {
        $instance = $group->taskable;
        $items = [
            new TaskRubricItem('check_script', $instance instanceof AppInstance && $this->workspace->definesComposerCheckScript($instance), 'composer.json in the workspace does not define a check script, so Orbit cannot run composer check. Restore the check script.'),
            $this->receiptItem($read, $receipt),
        ];
        $waiting = $this->waitingItem($thread);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }

        return $items;
    }

    private function receiptItem(?TaskRunReceipt $read, ?TaskComment $receipt): TaskRubricItem
    {
        if ($receipt instanceof TaskComment) {
            return new TaskRubricItem('run_receipt', true, '');
        }

        return new TaskRubricItem('run_receipt', false, $read instanceof TaskRunReceipt ? 'The run receipt was not valid for this turn.' : 'No run receipt was found.');
    }

    /**
     * Stores a receipt that fits the turn as a comment, then removes the file. A crash before the
     * removal reads the receipt again, and its hash matches the stored comment.
     *
     * @throws TaskRunReceiptException
     */
    private function collectReceipt(TaskGroup $group, Task $task, TaskThreadRole $role): ?TaskRunReceipt
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            throw new TaskRunReceiptException('The task workspace is unavailable.');
        }
        $receipt = $this->receipts->read($instance);
        if (! $receipt instanceof TaskRunReceipt) {
            return null;
        }
        if ($receipt->outcome instanceof TaskRunOutcome && $receipt->fits($role)) {
            TaskComment::query()->firstOrCreate(['task_id' => $task->id, 'receipt_hash' => $receipt->hash], [
                'task_group_id' => $group->id,
                'agent_thread_id' => $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id,
                'completion_attempt' => $task->completion_attempt,
                'review_attempt' => $role === TaskThreadRole::Reviewer ? $task->review_attempt : null,
                'type' => $receipt->outcome->commentType(),
                'body' => $receipt->summary,
                'pull_request' => $receipt->pullRequest?->toArray(),
                'author' => $role->value,
                'posted_at' => now(),
            ]);
        }
        $this->receipts->clear($instance, $receipt);

        return $receipt;
    }

    /**
     * Returns the latest stored receipt of this attempt that the scheduler has not acted on.
     */
    private function pendingReceipt(Task $task, TaskThreadRole $role): ?TaskComment
    {
        $implementer = $role === TaskThreadRole::Implementer;
        /** @var TaskComment|null $receipt */
        $receipt = $task->comments()
            ->whereNotNull('receipt_hash')
            ->where('author', $role->value)
            ->where($implementer ? 'completion_attempt' : 'review_attempt', $implementer ? $task->completion_attempt : $task->review_attempt)
            ->latest('id')
            ->first();
        $handled = $implementer ? $task->completion_handoff_comment_id : $task->review_handled_comment_id;

        return $receipt instanceof TaskComment && $receipt->id !== $handled ? $receipt : null;
    }

    private function receiptOutcome(TaskComment $receipt): ?TaskRunOutcome
    {
        return TaskRunOutcome::tryFrom((string) $receipt->getRawOriginal('type'));
    }

    /** @throws TaskRunReceiptException */
    private function prepareTurn(TaskGroup $group, Task $task, TaskThreadRole $role): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            throw new TaskRunReceiptException('The task workspace is unavailable.');
        }
        $this->receipts->prepare($instance, $role, $role === TaskThreadRole::Reviewer && $task->isLastSubtask());
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

    /**
     * Sends one reminder per attempt, then asks for assistance.
     *
     * @param  list<TaskRubricItem>  $items
     * @return bool whether the scheduler reminded the agent or asked for assistance
     */
    private function remindOrAssist(TaskGroup $group, Task $task, TaskThreadObservation $thread, array $items): bool
    {
        $failures = $this->failedItems($items);
        $implementer = $thread->role === TaskThreadRole::Implementer;
        $attempt = $implementer ? 'completion_attempt' : 'review_attempt';
        $reminder = $implementer ? 'completion_reminder_attempt' : 'review_reminder_attempt';
        $input = $implementer ? 'completion_reminder_input_id' : 'review_reminder_input_id';
        $pendingId = $thread->pendingUserInputId ?? $thread->pendingApprovalId;
        if ($task->{$reminder} === $task->{$attempt} && $pendingId !== null && $pendingId === $task->{$input}) {
            return false;
        }
        if ($task->{$reminder} !== $task->{$attempt}) {
            try {
                $this->prepareTurn($group, $task, $thread->role);
                $this->actor->remindRubric($group, $thread, TaskRubricReminder::compose($thread->role, $failures, ! $implementer && $task->isLastSubtask()));
            } catch (AgentDriverException|TaskRunReceiptException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return false;
            }
            $task->update([$reminder => $task->{$attempt}, $input => $pendingId, 'communication_failures' => 0]);

            return true;
        }

        $reasons = array_map(static fn (TaskRubricItem $item): string => $item->reminder, $failures);
        $this->requestAssistance($task, $group, 'Checks still failed after the reminder. '.implode(' ', $reasons));

        return true;
    }

    /** @param list<TaskRubricItem> $items
     * @return list<TaskRubricItem>
     */
    private function failedItems(array $items): array
    {
        return array_values(array_filter($items, static fn (TaskRubricItem $item): bool => ! $item->passed));
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

        $this->startFirstTask($started);

        return $started->fresh(['tasks', 'app', 'taskable']) ?? $started;
    }

    /**
     * Starts the reviewer at the first handoff, or asks the existing reviewer for the next review.
     */
    private function nudgeReviewer(Task $task, ?string $turnId): void
    {
        if ($task->review_notified_attempt === $task->review_attempt) {
            return;
        }
        $group = $task->taskGroup()->with('taskable')->firstOrFail();

        try {
            $this->prepareTurn($group, $task, TaskThreadRole::Reviewer);
            if ($group->reviewer_agent_thread_id === null) {
                $threadId = $this->spawner->spawnReviewer($task);
                if ($threadId === null) {
                    throw new AgentDriverException('The reviewer conversation could not be started.');
                }
                $group->update(['reviewer_agent_thread_id' => $threadId]);
            } else {
                $this->spawner->requestReview($task);
            }
        } catch (AgentDriverException|TaskRunReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

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

    private function startFirstTask(TaskGroup $group): void
    {
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

        $group = $task->taskGroup()->with('taskable')->first();
        $threadId = null;
        try {
            if ($group instanceof TaskGroup) {
                $this->prepareTurn($group, $task, TaskThreadRole::Implementer);
                $threadId = $this->spawner->spawnImplementer($task->fresh() ?? $task);
            }
        } catch (TaskRunReceiptException $exception) {
            Log::error('The run script could not be installed for the implementer.', ['task_id' => $task->id, 'reason' => $exception->getMessage()]);
        }

        if ($threadId === null) {
            $this->failSpawn($group, $task, 'implementer');

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
