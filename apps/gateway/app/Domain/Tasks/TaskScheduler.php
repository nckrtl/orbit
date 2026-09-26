<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TaskScheduler
{
    public const string ProvisioningFailedReason = 'Workspace provisioning did not return an instance.';

    public const string StartFailedReason = 'The group could not start after its workspace was provisioned.';

    public const string ReservationExpiredReason = 'The group stayed reserved too long and returned to todo.';

    public const string WorkspaceChangedReminder = 'The workspace changed during the review. Revert your changes and request the changes from the implementer instead.';

    /** Reasons the scheduler sets when a claim returns a group to todo. A start, a capacity wait, or a move to backlog clears them. */
    public const array ClaimFailureReasons = [
        self::ProvisioningFailedReason,
        self::StartFailedReason,
        self::ReservationExpiredReason,
    ];

    private const string BASELINE_COMPOSER_INSTALL_STEP = '[Orbit internal] Install Composer dependencies';

    private const string BASELINE_JAVASCRIPT_INSTALL_STEP = '[Orbit internal] Install JavaScript dependencies';

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
        private TaskBroadcasts $broadcasts,
        private RemoveTaskWorkspaceAction $workspaces,
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
            $health = $this->pullRequestWatcher->health($group);
            $status = $health?->state;
            if ($status === 'merged') {
                $this->completeMergedGroup($group);
            } elseif ($status === 'closed') {
                $group->update(['assistance_requested' => true, 'assistance_reason' => 'The expected pull request closed without merging.']);
            } elseif ($health instanceof TaskPullRequestHealth) {
                $this->reportPullRequestHealth($group, $health);
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
                    if ($task->status === TaskStatus::Reviewing) {
                        $group = $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
                        $this->retryCommittedApproval($group, $task);
                    }

                    continue;
                }

                $group = $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
                if ($task->status === TaskStatus::Reviewing && $this->retryCommittedApproval($group, $task)) {
                    continue;
                }
                if ($task->status === TaskStatus::Running && ! $this->hasImplementer($task)) {
                    $this->handleBaseline($group, $task);

                    continue;
                }
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
                    $this->advance($group, $task, $decision, $observation);
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
            $failures = TaskDeliverableVerifier::failures($task->deliverableList(), TaskDeliverableEvidence::fromArray($check->deliverable_evidence));
            if ($failures !== []) {
                $item = new TaskRubricItem('deliverables', false, "Orbit could not verify these deliverables:\n".implode("\n", array_map(static fn (string $failure): string => '- '.$failure, $failures))."\n");
                if ($this->remindOrAssist($group, $task, $implementer, [$item])) {
                    $task->update(['completion_handoff_comment_id' => $receipt->id]);
                }

                return;
            }
            $task->update(['completion_handoff_comment_id' => $receipt->id, 'communication_failures' => 0]);
            $this->settleImplementer($task, $observation->thread(TaskThreadRole::Reviewer));

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_comment_id', $receipt->id)->where('status', $check->status->value)->count()
            : 0;
        $command = $group->app->taskCheckCommand();
        $name = $command ?? 'the task check';
        $owned = $command === null ? "Orbit's task check" : "Orbit's {$command}";
        $item = match (true) {
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed => new TaskRubricItem('check_passed', false, "Orbit ran {$name}, and it failed with exit code {$check->exit_code}. The end of its output:\n\n```\n".rtrim((string) $check->output)."\n```\n"),
            $status === TaskCheckStatus::Cancelled => new TaskRubricItem('check_passed', false, "An operator cancelled {$owned} before it finished."),
            $check instanceof TaskCheck && $status === TaskCheckStatus::Changed && $repeats >= 2 => new TaskRubricItem('check_passed', false, "The workspace changed while {$name} ran, twice. Changed paths: ".implode(', ', $check->changed_paths ?? []).'. Make the check leave the tree unchanged, for example by ignoring the files it writes.'),
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
            $this->requestAssistance($task, $group, "{$owned} stopped twice without a result.", $observation);

            return;
        }

        try {
            $process = $this->checks->start($instance, $command, [], $this->deliverableCheck($task));
        } catch (TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        TaskCheck::query()->create([
            'task_id' => $task->id,
            'task_comment_id' => $receipt->id,
            'kind' => TaskCheckKind::Handoff,
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
        $changed = $reading->headAfter !== $check->head_before || $reading->treeAfter !== ($reading->treeBefore ?? $check->tree_before);
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
                'failed_step' => $reading->failedStep,
                'output' => $reading->output,
                'deliverable_evidence' => $reading->deliverables === null ? null : json_encode($reading->deliverables, JSON_THROW_ON_ERROR),
            ];
        $finishedAt = $reading->finishedAt === null ? now() : Carbon::createFromTimestamp($reading->finishedAt);
        $updated = TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running->value)
            ->update([...$values, 'finished_at' => $finishedAt, 'updated_at' => now()]);
        $check->refresh();
        $groupId = Task::query()->whereKey($check->task_id)->value('task_group_id');
        if ($updated === 1 && is_int($groupId)) {
            $this->broadcasts->groupChanged($groupId);
        }
    }

    private function handleReviewerOutcome(TaskGroup $group, Task $task, TaskSessionObservation $observation): bool
    {
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        if ($reviewer === null || $task->review_notified_attempt !== $task->review_attempt) {
            $this->nudgeReviewer($task, $reviewer);

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
        if ($outcome === TaskRunOutcome::Approved && $this->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            // Orbit commits the whole workspace, so the approval waits until the implementer stops changing it.
            return true;
        }
        if ($outcome === TaskRunOutcome::ChangesRequested && $this->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            return true;
        }
        if ($receipt instanceof TaskComment
            && in_array($outcome, [TaskRunOutcome::Blocked, TaskRunOutcome::ChangesRequested, TaskRunOutcome::Approved], true)
            && ! $this->workspaceUnchanged($group, $task, $reviewer, $receipt)) {
            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskRunOutcome::Approved && $this->committedApproval($receipt)) {
            // The commit is already stored and the workspace still holds it. Retry the push only; do not commit again.
            $this->publishApprovedCommit($group, $task, $receipt);

            return true;
        }
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
            $confirmation = $this->confirmationItem($task, $receipt, TaskThreadRole::Reviewer);
            if ($confirmation instanceof TaskRubricItem) {
                $items[] = $confirmation;
            }
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
        $this->publishApprovedCommit($group, $task, $receipt);

        return true;
    }

    /**
     * ADR 0160: the approval already names its commit, so a later tick pushes that commit and does not make another.
     */
    private function committedApproval(TaskComment $receipt): bool
    {
        return is_string($receipt->commit_sha) && $receipt->commit_sha !== '';
    }

    /**
     * Pushes an approved commit that is already stored, and reports whether this tick did that.
     * The tick calls this before it observes the reviewer, so an unavailable reviewer does not skip the retry.
     * A failed push asks for assistance on the fifth failure, and the tick keeps retrying it. This does not commit again.
     */
    private function retryCommittedApproval(TaskGroup $group, Task $task): bool
    {
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        if (! $receipt instanceof TaskComment || $this->receiptOutcome($receipt) !== TaskRunOutcome::Approved || ! $this->committedApproval($receipt)) {
            return false;
        }

        try {
            $current = $this->workspaceSnapshot($group);
        } catch (TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $tree = $task->review_workspace_tree;
        if ($current->head !== $receipt->commit_sha || (is_string($tree) && $tree !== '' && $current->tree !== $tree)) {
            // ADR 0133: the workspace no longer holds the approved commit. The reviewer outcome path reminds or asks for assistance.
            return false;
        }

        $this->publishApprovedCommit($group, $task, $receipt);

        return true;
    }

    /**
     * Pushes the approved HEAD, then opens the pull request on the last subtask. The open pushes again.
     * A failed push or open leaves the subtask in review and keeps commit_sha.
     */
    private function publishApprovedCommit(TaskGroup $group, Task $task, TaskComment $receipt): void
    {
        try {
            $this->publisher->push($group);
        } catch (TaskPullRequestException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }

        if ($task->isLastSubtask() && (! is_string($group->pr_url) || $group->pr_url === '')) {
            $pullRequest = TaskRunPullRequest::fromArray($receipt->pull_request);
            if (! $pullRequest instanceof TaskRunPullRequest) {
                $this->recordCommunicationFailure($task, $group, 'The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking.');

                return;
            }
            try {
                $url = $this->publisher->publish($group, TaskPullRequestDescription::render($pullRequest, $group->tasks()->whereNotIn('status', [TaskStatus::Cancelled, TaskStatus::Failed])->count(), $group->app->taskCheckCommand()));
            } catch (TaskPullRequestException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            $group->update(['pr_url' => $url]);
        }

        if ($group->assistance_requested) {
            $group->update(['assistance_requested' => false, 'assistance_reason' => null]);
        }
        $task->update([
            'review_handled_comment_id' => $receipt->id,
            'communication_failures' => 0,
            'assistance_requested' => false,
            'assistance_reason' => null,
        ]);
        $this->acceptReview($task);
    }

    private function relayFindings(TaskGroup $group, Task $task, TaskSessionObservation $observation, TaskComment $findings): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            $this->requestAssistance($task, $group, 'The implementer thread is unavailable for the review findings.', $observation);

            return;
        }
        if ($this->isWorking($implementer)) {
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
            new TaskRubricItem('check_script', ! self::runsComposerCheck($group->app->taskCheckCommand()) || $instance instanceof AppInstance && $this->workspace->definesComposerCheckScript($instance), 'composer.json in the workspace does not define a check script, so Orbit cannot run composer check. Restore the check script.'),
            $this->receiptItem($read, $receipt),
        ];
        $confirmation = $this->confirmationItem($task, $receipt, TaskThreadRole::Implementer);
        if ($confirmation instanceof TaskRubricItem) {
            $items[] = $confirmation;
        }
        $waiting = $this->waitingItem($thread);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }

        return $items;
    }

    /**
     * ADR 0133: a receipt confirms every deliverable it must, as the run script requires. A hand-written
     * receipt that misses one fails the `deliverables` item.
     */
    private function confirmationItem(Task $task, ?TaskComment $receipt, TaskThreadRole $role): ?TaskRubricItem
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === [] || ! $receipt instanceof TaskComment) {
            return null;
        }
        $missing = TaskDeliverableVerifier::unconfirmed($deliverables, $receipt->deliverables ?? [], $role);

        return new TaskRubricItem('deliverables', $missing === [], $missing === [] ? '' : 'The run receipt does not confirm the deliverables '.implode(', ', $missing).'. Pass --deliverable=ID=evidence for each one.');
    }

    /**
     * ADR 0133: what the handoff check needs to record deliverable evidence, or null for a subtask without deliverables.
     *
     * @return array{start: string|null, tests: list<array{id: string, project: string, file: string}>, commands: list<array{id: string, command: string, directory: string}>}|null
     */
    private function deliverableCheck(Task $task): ?array
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === []) {
            return null;
        }
        $tests = [];
        $commands = [];
        foreach ($deliverables as $deliverable) {
            if ($deliverable->type === TaskDeliverableType::Test) {
                $tests[] = ['id' => $deliverable->id, 'project' => TaskDeliverable::relative($deliverable->project) ?: '.', 'file' => TaskDeliverable::relative($deliverable->file)];
            } elseif ($deliverable->type === TaskDeliverableType::Command) {
                $commands[] = ['id' => $deliverable->id, 'command' => $deliverable->command, 'directory' => TaskDeliverable::relative($deliverable->directory) ?: '.'];
            }
        }

        return ['start' => $task->subtask_start_commit, 'tests' => $tests, 'commands' => $commands];
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
                'body' => $receipt->body(),
                'pull_request' => $receipt->pullRequest?->toArray(),
                'deliverables' => $receipt->deliverables === [] ? null : $receipt->deliverables,
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
        $this->receipts->prepare($instance, $role, $role === TaskThreadRole::Reviewer && $task->isLastSubtask(), $task->deliverableList());
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
                $this->actor->remindRubric($group, $thread, TaskRubricReminder::compose($thread->role, $failures, ! $implementer && $task->isLastSubtask(), $task->deliverableList(), $group->app->taskCheckCommand()));
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

    private function advance(TaskGroup $group, Task $task, TaskSessionDecision $decision, TaskSessionObservation $observation): void
    {
        $group = $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
        $current = $task->fresh();

        if ($decision->action === TaskSessionNextAction::MarkSubtaskDone && $current instanceof Task) {
            if ($current->status === TaskStatus::Running) {
                $this->settleImplementer($current, $observation->thread(TaskThreadRole::Reviewer));
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

    /**
     * Claims the oldest todo group that fits, provisions its Instance, and starts its first task.
     *
     * @param  list<int>  $skipped  Groups whose provisioning or start failed. A caller that passes the same list to later
     *                              calls tries each failing group at most once.
     */
    public function claimNext(array &$skipped = []): ?TaskGroup
    {
        while (true) {
            $reserved = DB::transaction(function () use ($skipped): ?TaskGroup {
                $candidates = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
                    ->with(['tasks', 'taskable'])
                    ->where('status', TaskGroupStatus::Todo)
                    ->when($skipped !== [], fn ($query) => $query->whereNotIn('id', $skipped))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($candidates as $group) {
                    if (! $this->ceilings->canActivate($group)) {
                        continue;
                    }

                    $group->status = TaskGroupStatus::Reserved;
                    $group->reserved_at = now();
                    $group->save();

                    return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
                }

                return null;
            });

            if (! $reserved instanceof TaskGroup) {
                return null;
            }

            try {
                $instance = $this->provisioning->provision(InstanceProvisionIntent::for($reserved));
            } catch (TaskCapacityException $exception) {
                $this->releaseReservation($reserved, self::isClaimFailureReason($reserved->assistance_reason) ? null : $reserved->assistance_reason);
                $this->removeEndedWorkspace($reserved, null);

                if ($exception->fleetFull) {
                    return null;
                }

                $skipped[] = $reserved->id;

                continue;
            } catch (Throwable $exception) {
                // An unexpected provisioning error must not strand the group in reserved. The log keeps the detail.
                report($exception);
                $instance = null;
            }

            if (! $instance instanceof AppInstance) {
                $this->releaseReservation($reserved, self::ProvisioningFailedReason);
                $this->removeEndedWorkspace($reserved, null);
                $skipped[] = $reserved->id;

                continue;
            }

            try {
                $started = DB::transaction(fn (): ?TaskGroup => $this->startReserved($reserved, $instance));
            } catch (Throwable $exception) {
                // A failed start must not strand the group in reserved or drop its Instance. The log keeps the detail.
                report($exception);
                $this->releaseFailedStart($reserved, $instance);
                $this->removeEndedWorkspace($reserved, $instance);
                $skipped[] = $reserved->id;

                continue;
            }

            break;
        }

        if (! $started instanceof TaskGroup) {
            $this->removeEndedWorkspace($reserved, $instance);

            return null;
        }

        $this->startFirstTask($started);

        return $started->fresh(['tasks', 'app', 'taskable']) ?? $started;
    }

    /**
     * Attaches the provisioned Instance and moves the group from reserved to running. The group keeps the Instance
     * whenever it cannot start, so a later claim reuses it and cancellation removes it.
     *
     * A group that is no longer the reservation this claim made, because the tick returned it to todo or cancellation
     * ended it, keeps its status. It gains the Instance only when it holds none.
     */
    private function startReserved(TaskGroup $reserved, AppInstance $instance): ?TaskGroup
    {
        $group = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
            ->with(['tasks', 'app', 'taskable'])
            ->lockForUpdate()
            ->findOrFail($reserved->id);

        if (! $this->holdsReservation($group, $reserved)) {
            if ($group->taskable_id === null && ! self::hasEnded($group)) {
                $group->taskable()->associate($instance);
                $group->save();
            }

            return null;
        }

        $group->taskable()->associate($instance);
        $group->load('taskable');

        if (! $this->ceilings->canActivate($group)) {
            $group->status = TaskGroupStatus::Todo;
            $group->save();

            return null;
        }

        $group->status = TaskGroupStatus::Running;
        $group->started_at ??= now();
        if (self::isClaimFailureReason($group->assistance_reason)) {
            $group->assistance_requested = false;
            $group->assistance_reason = null;
        }
        $group->save();

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    /**
     * Returns a group whose start failed to todo with a fixed reason and keeps its Instance. When this write fails
     * too, the group stays reserved until the tick returns it to todo.
     */
    private function releaseFailedStart(TaskGroup $reserved, AppInstance $instance): void
    {
        try {
            DB::transaction(function () use ($reserved, $instance): void {
                $group = TaskGroup::query()->lockForUpdate()->find($reserved->id);
                if (! $group instanceof TaskGroup) {
                    return;
                }
                if ($group->taskable_id === null && ! self::hasEnded($group)) {
                    $group->taskable()->associate($instance);
                }
                if ($this->holdsReservation($group, $reserved)) {
                    $group->status = TaskGroupStatus::Todo;
                    $group->assistance_reason = self::StartFailedReason;
                }
                $group->save();
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Returns a group to todo only while this claim still holds its reservation, so a claim never overwrites a
     * group that the tick released, a cancel ended, or a newer claim reserved.
     */
    private function releaseReservation(TaskGroup $reserved, ?string $reason): void
    {
        TaskGroup::query()->whereKey($reserved->id)
            ->where('status', TaskGroupStatus::Reserved)
            ->where('reserved_at', $reserved->reserved_at)
            ->update(['status' => TaskGroupStatus::Todo, 'assistance_reason' => $reason]);
    }

    /**
     * A group cancelled or completed while its claim ran holds no workspace, because the cancel left the
     * workspace to the claim. The claim removes the Instance it provisioned, or the group's unattached
     * `task-{group id}` workspace when provisioning failed part way.
     */
    private function removeEndedWorkspace(TaskGroup $reserved, ?AppInstance $instance): void
    {
        $group = TaskGroup::query()->with('taskable')->find($reserved->id);
        if (! $group instanceof TaskGroup || ! self::hasEnded($group) || $group->taskable_id !== null) {
            return;
        }

        try {
            $leftover = $instance instanceof AppInstance ? AppInstance::query()->find($instance->id) : $this->workspaces->find($group);
            if ($leftover instanceof AppInstance) {
                $this->workspaces->remove($leftover);
            }
        } catch (Throwable $exception) {
            // The workspace stays findable by name, so a repeated cancel removes it.
            report($exception);
            $this->workspaces->recordFailure($group, $exception);
        }
    }

    private static function hasEnded(TaskGroup $group): bool
    {
        return in_array($group->status, [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed], true);
    }

    private function holdsReservation(TaskGroup $group, TaskGroup $reserved): bool
    {
        return $group->status === TaskGroupStatus::Reserved
            && $group->reserved_at instanceof Carbon
            && $reserved->reserved_at instanceof Carbon
            && $group->reserved_at->equalTo($reserved->reserved_at);
    }

    /**
     * Returns groups that stayed reserved longer than `orbit.tasks.reserved_timeout_seconds` to todo, for example after
     * the process that claimed them stopped. Each update applies only while the group is still reserved and past the
     * bound, so it never takes a group that a newer claim reserved.
     */
    public function releaseStaleReservations(): int
    {
        $cutoff = RemoveTaskWorkspaceAction::reservationCutoff();
        $stale = static fn ($query) => $query->where('execution_mode', TaskExecutionMode::Managed)
            ->where('status', TaskGroupStatus::Reserved)
            ->where(static fn ($query) => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', $cutoff));
        $released = 0;

        foreach ($stale(TaskGroup::query())->orderBy('id')->pluck('id') as $id) {
            $updated = $stale(TaskGroup::query()->whereKey($id))->update([
                'status' => TaskGroupStatus::Todo,
                'assistance_reason' => self::ReservationExpiredReason,
            ]);
            if ($updated === 0) {
                continue;
            }

            Log::warning('Task group stayed reserved past the bound and returned to todo.', ['task_group_id' => $id]);
            $this->broadcasts->groupChanged((int) $id);
            $released++;
        }

        return $released;
    }

    /** Seconds one tick may spend removing abandoned workspaces, well inside the 300-second tick lock. */
    public const int AbandonedWorkspaceBudgetSeconds = 60;

    /** The first retry delay after a failed removal. Each further failure doubles it, up to the reservation timeout. */
    public const int AbandonedWorkspaceBackoffSeconds = 60;

    /**
     * Removes the workspace of a cancelled or completed group, attached or found by its `task-{group id}` name and
     * branch, and of a settling group whose merged pull request cleanup failed. An unattached workspace waits while
     * a live claim can still own it. The tick does not remove the workspace of a group that is still active.
     *
     * One query selects the candidates. A failed removal is reported, asks for assistance, and backs off per
     * Instance, so a workspace that keeps failing never blocks the others. The sweep stops starting removals once
     * it has spent its time budget; the rest wait for the next tick. Success clears that assistance.
     */
    public function removeAbandonedWorkspaces(): int
    {
        $started = now();
        $removed = 0;

        foreach ($this->abandonedWorkspaces() as $workspace) {
            if ($started->diffInSeconds(now(), true) >= self::AbandonedWorkspaceBudgetSeconds) {
                break;
            }

            $backoffKey = 'tasks.workspace-removal.'.$workspace->id;
            $backoff = $this->workspaceRemovalBackoff($backoffKey);
            if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
                continue;
            }

            $instance = AppInstance::query()->find($workspace->id);
            $group = TaskGroup::query()->find($workspace->getAttribute('ended_task_group_id'));
            if (! $instance instanceof AppInstance || ! $group instanceof TaskGroup || ! $this->shouldRemoveWorkspace($group, $instance)) {
                continue;
            }

            try {
                $this->workspaces->remove($instance);
                $this->rememberWorkspaceRemovalBackoff($backoffKey, null);
                $this->releaseRemovedWorkspace($group, $instance->id);
                Log::warning('Removed the workspace of an ended task group.', ['task_group_id' => $group->id, 'app_instance_id' => $instance->id]);
                $removed++;
            } catch (Throwable $exception) {
                report($exception);
                $prefix = $group->status === TaskGroupStatus::Settling
                    ? RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix
                    : RemoveTaskWorkspaceAction::RemovalFailedPrefix;
                $this->workspaces->recordFailure($group, $exception, $prefix);
                $this->extendWorkspaceRemovalBackoff($backoffKey, $backoff);
            }
        }

        return $removed;
    }

    /** Retries merged pull request cleanup at once the first time, then on the same per-Instance backoff as the sweep. */
    private function completeMergedGroup(TaskGroup $group): void
    {
        $attached = $group->taskable;
        $instance = $attached instanceof AppInstance ? $attached : $this->workspaces->find($group);
        $backoffKey = $instance instanceof AppInstance ? 'tasks.workspace-removal.'.$instance->id : null;
        $backoff = is_string($backoffKey) ? $this->workspaceRemovalBackoff($backoffKey) : null;

        if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
            return;
        }

        try {
            if (TaskPullRequestHealth::isReason($group->assistance_reason)) {
                $group->update(['assistance_requested' => false, 'assistance_reason' => null]);
            }
            $this->completeGroup->execute($group);
            if (is_string($backoffKey)) {
                $this->rememberWorkspaceRemovalBackoff($backoffKey, null);
            }
        } catch (Throwable $exception) {
            $group->update([
                'assistance_requested' => true,
                'assistance_reason' => RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.$exception->getMessage(),
            ]);
            if (is_string($backoffKey)) {
                $this->extendWorkspaceRemovalBackoff($backoffKey, $backoff);
            }
        }
    }

    private function shouldRemoveWorkspace(TaskGroup $group, AppInstance $instance): bool
    {
        if ($instance->app_id !== $group->app_id) {
            return false;
        }

        $attached = $group->taskable_id === $instance->id;
        $named = $group->taskable_id === null
            && $instance->name === TaskWorkspaceName::for($group)
            && $instance->branch_override === $instance->name;

        if (! $attached && ! $named) {
            return false;
        }

        if (in_array($group->status, [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed], true)) {
            return $attached
                || ! $group->reserved_at instanceof Carbon
                || $group->reserved_at->lessThanOrEqualTo(RemoveTaskWorkspaceAction::reservationCutoff());
        }

        return $group->status === TaskGroupStatus::Settling
            && is_string($group->assistance_reason)
            && str_starts_with($group->assistance_reason, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix);
    }

    private function releaseRemovedWorkspace(TaskGroup $group, int $instanceId): void
    {
        $group->refresh();

        if ($group->taskable_id === $instanceId) {
            $group->taskable()->dissociate();
            $group->save();
        }

        $this->workspaces->clearFailure($group);
    }

    /**
     * @param  array{failures: int, due: int}|null  $backoff
     */
    private function extendWorkspaceRemovalBackoff(string $key, ?array $backoff): void
    {
        $failures = ($backoff['failures'] ?? 0) + 1;
        $delay = min(
            self::AbandonedWorkspaceBackoffSeconds * 2 ** min($failures - 1, 20),
            max(self::AbandonedWorkspaceBackoffSeconds, (int) config('orbit.tasks.reserved_timeout_seconds')),
        );
        $this->rememberWorkspaceRemovalBackoff($key, ['failures' => $failures, 'due' => now()->addSeconds($delay)->getTimestamp()], $delay * 2);
    }

    /**
     * Reads a workspace removal backoff. A cache error is logged and read as no backoff, so one bad read never
     * stops the sweep or the tick.
     *
     * @return array{failures: int, due: int}|null
     */
    private function workspaceRemovalBackoff(string $key): ?array
    {
        try {
            $backoff = Cache::get($key);
        } catch (Throwable $exception) {
            Log::warning('The workspace removal backoff could not be read.', ['key' => $key, 'exception' => $exception::class, 'reason' => $exception->getMessage()]);

            return null;
        }

        return is_array($backoff) && is_int($backoff['failures'] ?? null) && is_int($backoff['due'] ?? null)
            ? ['failures' => $backoff['failures'], 'due' => $backoff['due']]
            : null;
    }

    /**
     * Stores or clears a workspace removal backoff. A cache error is logged and the sweep continues.
     *
     * @param  array{failures: int, due: int}|null  $backoff
     */
    private function rememberWorkspaceRemovalBackoff(string $key, ?array $backoff, int $seconds = 0): void
    {
        try {
            if ($backoff === null) {
                Cache::forget($key);
            } else {
                Cache::put($key, $backoff, now()->addSeconds($seconds));
            }
        } catch (Throwable $exception) {
            Log::warning('The workspace removal backoff could not be written.', ['key' => $key, 'exception' => $exception::class, 'reason' => $exception->getMessage()]);
        }
    }

    /** @return Collection<int, AppInstance> */
    private function abandonedWorkspaces(): Collection
    {
        $workspaceName = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT('task-', task_groups.id)",
            default => "'task-' || task_groups.id",
        };

        $cutoff = RemoveTaskWorkspaceAction::reservationCutoff();
        $mergePrefix = RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.'%';

        return AppInstance::query()
            ->select('app_instances.*', 'task_groups.id as ended_task_group_id')
            ->join('task_groups', function ($join) use ($workspaceName): void {
                $join->on('task_groups.app_id', '=', 'app_instances.app_id')
                    ->where(function ($link) use ($workspaceName): void {
                        $link->whereColumn('task_groups.taskable_id', 'app_instances.id')
                            ->orWhere(function ($named) use ($workspaceName): void {
                                $named->whereRaw("app_instances.name = {$workspaceName}")
                                    ->whereColumn('app_instances.branch_override', 'app_instances.name')
                                    ->whereNull('task_groups.taskable_id');
                            });
                    });
            })
            ->where('task_groups.execution_mode', TaskExecutionMode::Managed->value)
            ->where(function ($ended) use ($cutoff, $mergePrefix): void {
                $ended->where(function ($finished) use ($cutoff): void {
                    $finished->whereIn('task_groups.status', [TaskGroupStatus::Cancelled->value, TaskGroupStatus::Completed->value])
                        ->where(function ($reservation) use ($cutoff): void {
                            $reservation->whereColumn('task_groups.taskable_id', 'app_instances.id')
                                ->orWhereNull('task_groups.reserved_at')
                                ->orWhere('task_groups.reserved_at', '<=', $cutoff);
                        });
                })->orWhere(function ($settling) use ($mergePrefix): void {
                    $settling->where('task_groups.status', TaskGroupStatus::Settling->value)
                        ->where('task_groups.assistance_reason', 'like', $mergePrefix);
                });
            })
            ->orderBy('app_instances.id')
            ->get();
    }

    public static function isClaimFailureReason(?string $reason): bool
    {
        return in_array($reason, self::ClaimFailureReasons, true);
    }

    /** Claims todo groups until none fits. A failing group is tried once. */
    public function claimAvailable(): int
    {
        $skipped = [];
        $started = 0;

        while ($this->claimNext($skipped) instanceof TaskGroup) {
            $started++;
        }

        return $started;
    }

    /**
     * ADR 0133: a reviewer turn is read-only. The receipt stays unapplied while the workspace differs
     * from the pair recorded with the review request. One reminder, then the scheduler waits for a
     * newer stopped reviewer turn before it looks again. That later turn applies the outcome when
     * the workspace matches, and asks for assistance when it still differs. Another poll of the
     * reminded turn does neither. A failed read is a communication failure, not a change. A review
     * notified before a baseline existed has nothing to compare, so its outcome applies as before.
     * A commit already stored on the receipt is Orbit's. Publication retries only while HEAD is
     * that commit and the working tree still matches. The HEAD from before the approval is not
     * accepted after Orbit has committed, so a reset that drops the commit is refused.
     *
     * @return bool whether the workspace still matches and the outcome may be applied
     */
    private function workspaceUnchanged(TaskGroup $group, Task $task, TaskThreadObservation $reviewer, TaskComment $receipt): bool
    {
        try {
            $current = $this->workspaceSnapshot($group);
        } catch (TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return false;
        }
        $head = $task->review_workspace_head;
        $tree = $task->review_workspace_tree;
        $storedCommit = $receipt->commit_sha;
        $orbitCommit = is_string($storedCommit) && $storedCommit !== '' ? $storedCommit : null;
        $treeMatches = ! is_string($tree) || $tree === '' || $current->tree === $tree;
        $headMatches = $orbitCommit !== null
            ? $current->head === $orbitCommit
            : ! is_string($head) || $head === '' || $current->head === $head;
        if ($headMatches && $treeMatches) {
            return true;
        }
        $this->remindOrAssist($group, $task, $reviewer, [
            new TaskRubricItem('workspace_unchanged', false, self::WorkspaceChangedReminder),
        ]);
        if ($task->assistance_requested) {
            $task->update(['review_handled_comment_id' => $receipt->id]);
        } elseif ($task->review_reminder_attempt === $task->review_attempt && is_string($reviewer->turnId) && $reviewer->turnId !== '') {
            // The same stopped turn is not a second change. The next look waits for a newer one.
            $task->update(['review_notified_turn_id' => $reviewer->turnId]);
        }

        return false;
    }

    /** @throws TaskCheckException */
    private function workspaceSnapshot(TaskGroup $group): TaskWorkspaceSnapshot
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }

        return $this->checks->snapshot($instance);
    }

    /**
     * Starts the reviewer at the first handoff, or asks the existing reviewer for the next review.
     * A working reviewer gets no request. The task stays unnotified, so a later tick sends the request
     * once the reviewer is idle.
     */
    private function nudgeReviewer(Task $task, ?TaskThreadObservation $reviewer): void
    {
        if ($task->review_notified_attempt === $task->review_attempt || $this->isWorking($reviewer)) {
            return;
        }
        $group = $task->taskGroup()->with('taskable')->firstOrFail();

        try {
            // Read at send time. Do not copy the hash from an earlier check row: the request may have waited.
            $snapshot = $this->workspaceSnapshot($group);
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
        } catch (AgentDriverException|TaskRunReceiptException|TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }

        $task->update([
            'review_notified_attempt' => $task->review_attempt,
            'review_notified_turn_id' => $reviewer?->turnId,
            'review_workspace_head' => $snapshot->head,
            'review_workspace_tree' => $snapshot->tree,
        ]);
        $this->clearCommunicationFailures($task);
    }

    /**
     * A working thread never receives a turn; the scheduler sends it on a later tick.
     */
    private function isWorking(?TaskThreadObservation $thread): bool
    {
        return $thread?->sessState === AgentThreadState::Working->value;
    }

    private function newerTurnHasStopped(?string $previousTurnId, TaskThreadObservation $thread): bool
    {
        return is_string($thread->turnId) && $thread->turnId !== ''
            && $thread->turnId !== $previousTurnId
            && in_array($thread->sessState, [AgentThreadState::Done->value, AgentThreadState::AskingForInput->value], true);
    }

    public function settleImplementer(Task $task, ?TaskThreadObservation $reviewer = null): TaskGroup
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
            $this->nudgeReviewer($reviewing, $reviewer);
        }

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    public function startTask(Task $task): TaskGroup
    {
        $task->taskGroup->requireManagedExecution();
        $started = $this->activateRunningTask($task);
        $this->recordSubtaskStart($started);
        if ($this->needsBaseline($started)) {
            $group = $started->taskGroup()->with(['app', 'taskable'])->firstOrFail();
            $this->startBaseline($group, $started);
        } else {
            $this->assignImplementer($started);
        }

        $group = $started->taskGroup;

        return $group->fresh(['tasks', 'app', 'taskable']) ?? $group;
    }

    /**
     * Cancels a running subtask and starts the next one. `$stop` makes the remote calls that stop the
     * subtask's implementer and check. It runs outside any database transaction, so a slow Node or agent
     * never holds the Gateway's SQLite write lock. An exception from `$stop` leaves the subtask and its
     * check running. The state change then applies only when the subtask is still running: when it moved
     * on while `$stop` ran, its new state stands and the cancel returns a conflict.
     *
     * @param  Closure(Task): void  $stop
     */
    public function cancelRunningSubtask(TaskGroup $taskGroup, Task $task, Closure $stop): TaskGroup
    {
        $taskGroup->requireManagedExecution();
        $running = Task::query()->where('task_group_id', $taskGroup->id)->findOrFail($task->id);
        if ($running->status !== TaskStatus::Running) {
            throw new ResourceOperationException(
                errorCode: 'tasks.subtask_not_running',
                message: __('Only a running subtask can be cancelled.'),
                status: 409,
            );
        }

        $stop($running);

        /** @var Task|null $next */
        $next = null;
        $group = DB::transaction(function () use ($taskGroup, $task, &$next): TaskGroup {
            $locked = Task::query()->where('task_group_id', $taskGroup->id)->lockForUpdate()->findOrFail($task->id);
            $group = TaskGroup::query()->where('execution_mode', TaskExecutionMode::Managed)
                ->lockForUpdate()
                ->findOrFail($locked->task_group_id);

            if ($locked->status !== TaskStatus::Running) {
                throw new ResourceOperationException(
                    errorCode: 'tasks.subtask_not_running',
                    message: __('The subtask stopped running while Orbit stopped it, so its new state stands.'),
                    status: 409,
                );
            }

            TaskCheck::query()->where('task_id', $locked->id)
                ->where('status', TaskCheckStatus::Running->value)
                ->update(['status' => TaskCheckStatus::Cancelled->value, 'finished_at' => now(), 'updated_at' => now()]);

            $assistanceReason = $locked->assistance_reason;
            $locked->update([
                'status' => TaskStatus::Cancelled,
                'settled_at' => now(),
                'completion_summary' => 'Cancelled by operator.',
                'assistance_requested' => false,
                'assistance_reason' => null,
            ]);

            $tasks = $this->lockedTasks($group);
            if ($group->assistance_requested && $assistanceReason !== null && $group->assistance_reason === $assistanceReason) {
                $otherAssistance = $group->tasks()
                    ->whereKeyNot($locked->id)
                    ->where('assistance_requested', true)
                    ->exists();

                if (! $otherAssistance) {
                    $group->assistance_requested = false;
                    $group->assistance_reason = null;
                }
            }

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
            $this->beginRunningTask($next);
        }

        if ($group->status === TaskGroupStatus::Settling) {
            return $this->settle($group);
        }

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

        $reason = 'The settling group has no reviewed pull request URL. Cancel the group to push its approved commits to task-'.$group->id.' and remove its workspace.';
        $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
        $this->coder->assistance($group, $reason);
    }

    /**
     * Asks for assistance once per distinct set of pull request problems, and withdraws only its own
     * request when the pull request is healthy again. Another cause of assistance is left alone.
     */
    private function reportPullRequestHealth(TaskGroup $group, TaskPullRequestHealth $health): void
    {
        $ownRequest = TaskPullRequestHealth::isReason($group->assistance_reason);

        if ($health->problems === []) {
            if ($ownRequest) {
                $group->update(['assistance_requested' => false, 'assistance_reason' => null]);
            }

            return;
        }

        $reason = $health->reason();
        if ($group->assistance_requested && (! $ownRequest || $group->assistance_reason === $reason)) {
            return;
        }

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

    /**
     * Starts a subtask the way startTask does: records the start commit, then runs the baseline check
     * when no implementer has started in the group yet, or starts the implementer.
     */
    private function beginRunningTask(Task $task): void
    {
        $this->recordSubtaskStart($task);
        if ($this->needsBaseline($task)) {
            $group = $task->taskGroup()->with(['app', 'taskable'])->firstOrFail();
            $this->startBaseline($group, $task);
        } else {
            $this->assignImplementer($task);
        }
    }

    /**
     * A group checks its fresh workspace once, before any implementer has started in it.
     */
    private function needsBaseline(Task $task): bool
    {
        $started = Task::query()->where('task_group_id', $task->task_group_id)->whereNotNull('implementer_agent_thread_id')->exists()
            || AgentThread::query()->where('task_group_id', $task->task_group_id)->where('role', TaskThreadRole::Implementer->value)->exists();

        return ! $started && ! TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)
            ->where('status', TaskCheckStatus::Passed->value)->exists();
    }

    private function hasImplementer(Task $task): bool
    {
        return $task->implementer_agent_thread_id !== null
            || AgentThread::query()->where('task_id', $task->id)->where('role', TaskThreadRole::Implementer->value)->exists();
    }

    /**
     * Runs the Project's setup steps and check on the fresh workspace. The first implementer starts only
     * after it passes, so an agent never starts on a broken checkout.
     */
    private function handleBaseline(TaskGroup $group, Task $task): void
    {
        /** @var TaskCheck|null $check */
        $check = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->latest('id')->first();
        $instance = $group->taskable;
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && $instance instanceof AppInstance) {
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
        if ($status === TaskCheckStatus::Passed) {
            $this->clearCommunicationFailures($task);
            $this->assignImplementer($task);

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->where('status', $check->status->value)->count()
            : 0;
        $branch = 'task-'.$group->id;
        $reason = match (true) {
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step === self::BASELINE_COMPOSER_INSTALL_STEP => "Composer dependency installation failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Restore the required dependencies, then cancel and create the group again. The task's check shows the install output.",
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step === self::BASELINE_JAVASCRIPT_INSTALL_STEP => "JavaScript dependency installation failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Restore the required dependencies, then cancel and create the group again. The task's check shows the install output.",
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step === null && $this->checkOutputShowsMissingDependencies($check) => "Project dependencies appear to be missing on a fresh checkout of {$branch}, before any agent started. Install the required dependencies, then cancel and create the group again. The task's check shows the missing-dependency output.",
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step !== null => "The Project setup step \"{$check->failed_step}\" failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Fix the setup or the branch, then cancel and create the group again. The task's check shows the output.",
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed => "The Project baseline check failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Fix the configured check or the branch, then cancel and create the group again. The task's check shows the output.",
            $status === TaskCheckStatus::Cancelled => 'An operator cancelled the baseline check before any agent started.',
            $check instanceof TaskCheck && $status === TaskCheckStatus::Changed && $repeats >= 2 => 'The workspace changed while the baseline check ran, twice. Changed paths: '.implode(', ', $check->changed_paths ?? []).'.',
            $status === TaskCheckStatus::Lost && $repeats >= 2 => 'The baseline check stopped twice without a result.',
            default => null,
        };
        if ($reason !== null) {
            $this->requestAssistance($task, $group, $reason);

            return;
        }

        $this->startBaseline($group, $task);
    }

    /**
     * Only a task check that runs `composer check` needs the workspace's Composer `check` script.
     */
    private static function runsComposerCheck(?string $command): bool
    {
        return $command !== null && preg_match('/(?:^|[\s;&|(])composer\s+check(?=$|[\s;&|)])/', $command) === 1;
    }

    private function checkOutputShowsMissingDependencies(TaskCheck $check): bool
    {
        return preg_match(
            '/(?:vendor\\/bin\\/[^:\\s]+|node_modules\\/\\.bin\\/[^:\\s]+):\\s*(?:not found|No such file or directory)|(?:vendor\\/autoload\\.php|node_modules\\/[^\\s]+).{0,160}(?:failed to open stream|Failed opening required|No such file|not found)|Failed opening required [\'\"][^\'\"]*(?:vendor\\/autoload\\.php|node_modules\\/)/i',
            (string) $check->output,
        ) === 1;
    }

    private function startBaseline(TaskGroup $group, Task $task): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }
        $setup = ProjectLifecycleStep::query()
            ->where('app_id', $group->app_id)
            ->where('phase', LifecyclePhase::Setup->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (ProjectLifecycleStep $step): array => ['name' => $step->name, 'command' => $step->command, 'timeout_seconds' => $step->timeout_seconds])
            ->values()
            ->all();
        $command = $instance->app->taskCheckCommand();
        if ($command !== null && preg_match('/(?:^|[\\s;&|(])composer(?=$|\\s)|\\bvendor\\//i', $command) === 1) {
            $setup[] = [
                'name' => self::BASELINE_COMPOSER_INSTALL_STEP,
                'command' => 'while IFS= read -r -d "" manifest; do project="${manifest%/composer.json}"; [ "$project" = "$manifest" ] && project="."; if { [ "$project" = "." ] || [ -f "$project/composer.lock" ]; } && [ ! -f "$project/vendor/autoload.php" ]; then (cd "$project" && if [ -f composer.lock ]; then composer install --no-interaction --prefer-dist; else composer install --no-interaction --prefer-dist && rm -f composer.lock; fi) || exit $?; fi; done < <(git ls-files -z -- "composer.json" ":(glob)**/composer.json")',
                'timeout_seconds' => 600,
            ];
        }
        if ($command !== null && preg_match('/\\b(?:bun|npm|pnpm|yarn|node|vp)\\b|node_modules/i', $command) === 1) {
            $setup[] = [
                'name' => self::BASELINE_JAVASCRIPT_INSTALL_STEP,
                'command' => 'while IFS= read -r -d "" manifest; do project="${manifest%/package.json}"; [ "$project" = "$manifest" ] && project="."; if [ ! -d "$project/node_modules" ] && { [ -f "$project/pnpm-lock.yaml" ] || [ -f "$project/bun.lock" ] || [ -f "$project/bun.lockb" ] || [ -f "$project/package-lock.json" ] || [ -f "$project/yarn.lock" ]; }; then (cd "$project" && vp install --frozen-lockfile) || exit $?; fi; done < <(git ls-files -z -- "package.json" ":(glob)**/package.json")',
                'timeout_seconds' => 600,
            ];
        }
        try {
            $process = $this->checks->start($instance, $command, $setup);
        } catch (TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        TaskCheck::query()->create([
            'task_id' => $task->id,
            'kind' => TaskCheckKind::Baseline,
            'status' => TaskCheckStatus::Running,
            'pid' => $process->pid,
            'process_started' => $process->started,
            'head_before' => $process->head,
            'tree_before' => $process->tree,
            'started_at' => now(),
        ]);
        $this->clearCommunicationFailures($task);
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
            ->every(static fn (Task $candidate): bool => in_array($candidate->status, [
                TaskStatus::Completed,
                TaskStatus::Cancelled,
                TaskStatus::Failed,
            ], true));
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
}
