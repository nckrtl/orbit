<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskSandboxAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Actions\Tasks\WatchTaskBranchPullRequestAction;
use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Infrastructure\Compute\TaskSandboxGroupLifecycle;
use App\Infrastructure\Compute\TaskSandboxWarmPool;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Models\TaskReviewedCommit;
use App\Models\TaskSandbox;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class TaskScheduler
{
    public const string ProvisioningFailedReason = 'Workspace provisioning did not return an instance.';

    public const string StartFailedReason = 'The group could not start after its workspace was provisioned.';

    public const string ReservationExpiredReason = 'The group stayed reserved too long and returned to todo.';

    public const string WorkspaceChangedReminder = 'The workspace changed during the review. Revert your changes and request the changes from the implementer instead.';

    /** Assistance set when an approved commit cannot be pushed or its pull request cannot be opened. */
    public const string PublicationFailedPrefix = 'Approved commit publication failed: ';

    /** Assistance set when an approved commit would reach, or reached, a pull request that already merged or closed (ADR 0164). */
    public const string OrphanedCommitPrefix = 'An approved commit is not on the pull request: ';

    /** Assistance set when a settling group has no pull request and no todo subtask (ADR 0164). */
    public const string MissingPullRequestPrefix = 'The settling group has no reviewed pull request URL.';

    /** Assistance set when a review request fails. The exception class follows; the message does not. */
    public const string ReviewRequestFailedReason = 'The review could not be requested';

    /** Re-evaluations of cancelled or unstarted checks on one head before the group asks for assistance (ADR 0164). */
    public const int InfrastructureCheckRetries = 5;

    /** The Pi server reports this when it restarted while a turn was still active (ADR 0116, ADR 0167). */
    public const string PiServerRestartError = 'The Pi server restarted during the turn.';

    /** One continue, on the same thread, after that restart. It does not ask for assistance. */
    public const string PiServerRestartContinue = 'Your previous turn was interrupted by a server restart. Check git status and git diff, finish the subtask, and hand off with the turn command.';

    /** Resumes reserved for one subtask before the next restart asks for assistance (ADR 0167). */
    public const int PiServerRestartResumeLimit = 2;

    private const string WorkspaceStartedActivity = 'Task workspace started.';

    private const string PiRestartPending = 'pending';

    private const string PiRestartAccepted = 'accepted';

    private const string PiRestartSuperseded = 'superseded';

    /** Reasons the scheduler sets when a claim returns a group to todo. A start, a capacity wait, or a move to backlog clears them. */
    public const array ClaimFailureReasons = [
        self::ProvisioningFailedReason,
        self::StartFailedReason,
        self::ReservationExpiredReason,
    ];

    /** A baseline row reserved before its process exists. A real check pid is at least 1. */
    private const int BASELINE_UNSTARTED_PID = 0;

    /**
     * How long a baseline start may stay unrecorded. This matches the SSH command timeout.
     * A claim older than that was interrupted, and its process may still be running.
     */
    public const int BASELINE_START_LIMIT_SECONDS = 900;

    /** The check script sets this when a command deliverable names an invalid directory or overlay path. */
    private const string INVALID_DELIVERABLE_STEP = 'invalid_deliverable';

    /** The check script sets this when an unexpected error still leaves a result. */
    private const string CHECK_ERROR_STEP = 'check_error';

    public function __construct(
        private TaskConcurrencyGuard $ceilings,
        private InstanceProvisioning $provisioning,
        private AgentSpawner $spawner,
        private TaskSettleMetricsCollector $metrics,
        private TaskWorkspaceStateReader $workspace,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private TaskPullRequestUpdater $pullRequestUpdater,
        private CompleteTaskGroupAction $completeGroup,
        private CoderSettleNotifier $coder,
        private TaskExtensionState $extension,
        private TaskSessionObserver $observer,
        private TaskSessionActor $actor,
        private TaskTurnReceipts $receipts,
        private TaskWorkspaceSigner $signer,
        private TaskBriefCoverage $coverage,
        private BriefCoverageLabeler $coverageLabeler,
        private TaskPullRequestPublisher $publisher,
        private TaskCheckRunner $checks,
        private TaskBroadcasts $broadcasts,
        private RemoveTaskWorkspaceAction $workspaces,
        private TaskBaseBranchFetcher $bases,
        private PrunePendingTaskThreads $pendingThreads,
        private TaskReviewPacketBuilder $reviewPackets,
        private WatchTaskBranchPullRequestAction $branchPullRequests,
        private RequestEndedPullRequestAssistanceAction $endedPullRequests,
        private TaskTurnFetcher $turnFetcher,
        private RetryTaskBaselineAction $retryBaseline,
        private TaskPullRequestReviewWatcher $reviewWatcher,
        private TaskGitHubReviewConsumption $reviewConsumption,
        private TaskGitHubReviewFeedback $reviewFeedback,
        private TaskWorkspaceTopology $topology,
        private TaskTopologyAdmission $topologyAdmission,
        private TaskSandboxGroupLifecycle $sandboxes,
        private TaskSandboxWarmPool $warmPool,
        private TaskPullRequestMerger $merger,
        private DeliverablePathChecker $deliverablePaths,
        private ResumeDeliverableCorrectionAction $correctionResume,
    ) {}

    /**
     * @return list<TaskSessionDecision>
     */
    public function tick(): array
    {
        if (! $this->extension->enabled()) {
            return [];
        }

        $groups = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
            ->with(['project', 'tasks', 'taskable'])
            ->whereIn('status', [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview])
            ->orderBy('id')
            ->get();
        $runningAtStart = [];
        foreach ($groups as $group) {
            if ($group->status === TaskGroupStatus::Running) {
                $runningAtStart[$group->id] = true;
            }
        }

        foreach ($groups as $group) {
            $this->reportMissingReviewFixups($group);
            if (in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)
                && in_array($group->watched_pr_completion, ['merged', 'closed'], true)) {
                try {
                    $this->completeGroup->execute($group);
                } catch (Throwable $exception) {
                    report($exception);
                }

                continue;
            }
            $this->branchPullRequests->execute($group);
            if ($this->endedPullRequests->execute($group)) {
                continue;
            }
            if (! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
                continue;
            }
            if (! is_string($group->pr_url) || $group->pr_url === '') {
                if ($this->lowestTodo($group->tasks) instanceof Task) {
                    $this->resumeWaitingSubtask($group);
                } elseif (TaskFinalReview::due($group, $group->tasks)) {
                    $this->beginFinalReview($group);
                } else {
                    $this->requestMissingPullRequest($group);
                }

                continue;
            }
            $reviews = $this->observeReviewFeedback($group);
            $health = $this->pullRequestWatcher->health($group);
            $status = $health?->state;
            // With open work, only the branch watch decides which pull request ended. Never
            // auto-complete from pr_url here: it can name an older, reviewed pull request.
            if (in_array($status, ['merged', 'closed'], true) && $group->tasks->contains(
                static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true),
            )) {
                continue;
            }
            if ($status === 'merged') {
                try {
                    $this->coverageLabeler->label($group, $health);
                } catch (Throwable $exception) {
                    try {
                        report($exception);
                    } catch (Throwable) {
                    }
                }
                // Revalidate even when a prior tick committed settling but stopped before its hold write.
                $missedApproval = $this->checkReturningPullRequest($group, $health);
                if (! $missedApproval && ! $this->orphanedCommit($group)) {
                    $this->completeMergedGroup($group);
                }
            } elseif ($status === 'closed') {
                TaskAssistance::apply($group, AssistanceKind::Failure, null, 'The expected pull request closed without merging.', replaceFailure: true);
            } elseif ($health instanceof TaskPullRequestHealth) {
                $this->healOpenPullRequest($group, $health, $reviews);
                if ($group->reviewsBeforePush()) {
                    $this->mergeWhenReady($group, $health, $reviews);
                }
            }
            $this->sandboxes->review($group);
        }

        $decisions = [];

        foreach ($groups as $group) {
            if ($group->status === TaskGroupStatus::Completed
                || in_array($group->watched_pr_completion, ['merged', 'closed'], true)
                || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)) {
                continue;
            }
            if (isset($runningAtStart[$group->id])) {
                $this->resumeStrandedSubtask($group);
            }
            $tasks = $group->tasks
                ->filter(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true))
                ->sortBy(static fn (Task $task): array => [$task->position, $task->id]);

            foreach ($tasks as $task) {
                $task = $task->fresh();

                if (! $task instanceof Task || ! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
                    continue;
                }
                if ($task->status === TaskStatus::Running && ($task->deliverable_correction_resume['state'] ?? null) === 'pending') {
                    $this->correctionResume->execute($task);
                    $task->refresh();
                    $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                }
                if ($task->status === TaskStatus::Running && $task->assistance_requested && $this->queueBaselineRetryOnGreenTip($group, $task)) {
                    $task->refresh();
                }
                if ($task->status === TaskStatus::Running && $task->assistance_requested && $task->resolution_delivered_comment_id !== null) {
                    try {
                        if (TaskExecutionHold::run($group, fn (): bool => $this->retryBaseline->recover($task)) === true) {
                            $task->refresh();
                            $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                        }
                    } catch (AgentDriverException $exception) {
                        $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                        continue;
                    }
                }
                if ($task->status === TaskStatus::Running) {
                    $this->recordSubtaskStart($task);
                }
                if ($task->assistance_requested || $this->progressBlockedByAssistance($group)) {
                    if ($task->assistance_requested && ! $group->assistance_requested && $task->assistance_kind instanceof AssistanceKind && is_string($task->assistance_reason)) {
                        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                        if (! $group->assistance_requested) {
                            $this->requestAssistance($task, $group, $task->assistance_reason, null, $task->assistance_kind, $task->assistance_question);
                        }
                    }
                    if ($task->status === TaskStatus::Reviewing) {
                        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                        $this->retryCommittedApproval($group, $task);
                    }
                    $resolution = $task->status === TaskStatus::Running ? $this->pendingRelayResolution($task) : null;
                    if ($resolution instanceof TaskComment && $this->spawner instanceof TaskAgentSpawner && $this->subtaskReviewer($task) === null) {
                        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                        $this->beginDirectionRelay($group, $task, $resolution);
                    }
                    if ($task->status === TaskStatus::Reviewing && $task->assistance_kind === AssistanceKind::Direction && $this->subtaskReviewer($task) === null) {
                        $this->replayHeldDirectionReview($task);
                    }

                    continue;
                }

                $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                if ($task->status === TaskStatus::Running && $task->direction_relay_comment_id !== null) {
                    $this->handleDirectionRelay($group, $task);

                    continue;
                }
                if ($task->status === TaskStatus::Running && $task->consult_comment_id !== null) {
                    $this->handleConsult($group, $task);

                    continue;
                }
                if ($task->status === TaskStatus::Reviewing && $this->retryCommittedApproval($group, $task)) {
                    continue;
                }
                if ($task->status === TaskStatus::Running && ! $this->hasImplementer($task)) {
                    $this->beginRunningTask($task);

                    continue;
                }
                if ($task->status === TaskStatus::Reviewing && $task->isFinalReview() && $task->review_notified_attempt !== $task->review_attempt) {
                    $this->nudgeReviewer($task, null);

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
                        $this->requestAssistance($task, $group, $exception->getMessage());
                    }
                    $this->actor->execute($group, $observation, $decision);
                }

                $decisions[] = $decision;
            }
        }

        $this->pendingThreads->run();

        return $decisions;
    }

    private function handleImplementerCompletion(Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        return TaskExecutionHold::run($group, fn (): bool => $this->handleAdmittedImplementerCompletion($group, $task, $observation)) ?? true;
    }

    private function handleAdmittedImplementerCompletion(Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            return false;
        }
        $this->reconcilePiRestart($task, $implementer);
        $state = AgentThreadState::tryFrom($implementer->sessState);
        if ($state === AgentThreadState::Failed) {
            if ($this->resumePiServerRestart($task, $group, $implementer) !== 'handled') {
                $this->requestAssistance($task, $group, 'The implementer thread failed.', $observation);
            }

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }

        if ($task->completion_handoff_attempt !== null && ! $this->newerTurnHasStopped($task->completion_handoff_turn_id, $implementer)) {
            return true;
        }
        if ($this->reissueLegacyTurn($group, $task, $implementer)) {
            return true;
        }

        try {
            $read = $this->collectReceipt($group, $task, TaskThreadRole::Implementer);
        } catch (TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Implementer);
        if ($this->resumeTopologyRequest($group, $task, $implementer, $receipt)) {
            return true;
        }
        if ($receipt instanceof TaskComment && $this->receiptOutcome($receipt) === TaskTurnOutcome::Blocked) {
            $this->beginConsult($group, $task, $receipt, $observation);

            return true;
        }

        $items = $this->implementerItems($task, $implementer, $read, $receipt);
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
    private function checkHandoff(Task $group, Task $task, TaskThreadObservation $implementer, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }
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
            $handoff = ['completion_handoff_comment_id' => $receipt->id, 'communication_failures' => 0];
            if (is_string($implementer->turnId) && $implementer->turnId !== '') {
                // The turn that handed off. A later implementer turn during review is a new handoff, not a reviewer edit.
                $handoff['completion_handoff_turn_id'] = $implementer->turnId;
            }
            $task->update($handoff);
            $this->settleImplementer($task, $observation->thread(TaskThreadRole::Reviewer));

            return;
        }
        // The implementer cannot change deliverables, so an invalid project or file asks for assistance with no reminder.
        if ($check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step === self::INVALID_DELIVERABLE_STEP) {
            $reason = trim((string) $check->output);
            $this->requestAssistance(
                $task,
                $group,
                $reason !== '' ? $reason : 'A command deliverable names an invalid directory or overlay path.',
                $observation,
                handled: ['completion_handoff_comment_id' => $receipt->id],
            );

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_comment_id', $receipt->id)->where('status', $check->status->value)->count()
            : 0;
        $command = $group->project->taskCheckCommand();
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
            $this->requestAssistance($task, $group, "{$owned} stopped twice without a result.", $observation, handled: ['completion_handoff_comment_id' => $receipt->id]);

            return;
        }

        try {
            $process = $this->checks->start($instance, $command, [], $this->handoffCheck($task));
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
                    // A changed tree must not hide these. The task would retry as changed and drop the cause.
                    $reading->failedStep === self::CHECK_ERROR_STEP || $reading->failedStep === self::INVALID_DELIVERABLE_STEP => TaskCheckStatus::Failed->value,
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
        $groupId = Task::query()->whereKey($check->task_id)->value('parent_id');
        if ($updated === 1 && is_int($groupId)) {
            $this->broadcasts->groupChanged($groupId);
        }
    }

    private function handleReviewerOutcome(Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        return TaskExecutionHold::run($group, fn (): bool => $this->handleAdmittedReviewerOutcome($group, $task, $observation)) ?? true;
    }

    private function handleAdmittedReviewerOutcome(Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        // Before this subtask's review is requested, the observed reviewer can be an earlier
        // subtask's thread. A Pi restart of that turn is not this review. Request the review
        // first, and recover only a review that was already requested (ADR 0167, ADR 0169).
        if ($reviewer === null || $task->review_notified_attempt !== $task->review_attempt) {
            $this->nudgeReviewer($task, $reviewer);

            return true;
        }
        $this->reconcilePiRestart($task, $reviewer);
        $state = AgentThreadState::tryFrom($reviewer->sessState);
        if ($state === AgentThreadState::Failed) {
            if ($this->resumePiServerRestart($task, $group, $reviewer) !== 'handled') {
                $this->requestAssistance($task, $group, 'The reviewer thread failed.', $observation);
            }

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }
        if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return true;
        }
        if ($this->reissueLegacyTurn($group, $task, $reviewer)) {
            return true;
        }

        try {
            $read = $this->collectReceipt($group, $task, TaskThreadRole::Reviewer);
        } catch (TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        if ($this->resumeTopologyRequest($group, $task, $reviewer, $receipt)) {
            return true;
        }
        $outcome = $receipt instanceof TaskComment ? $this->receiptOutcome($receipt) : null;
        if ($receipt instanceof TaskComment && TaskQuestions::awaitsCause($task) && ! $receipt->cause instanceof QuestionCause) {
            $this->remindOrAssist($group, $task, $reviewer, [
                new TaskRubricItem('turn_receipt', false, 'This review follows a direction resolution and needs --cause with one of: brief_unclear, contract_gap, scope, environment, missed_contract.'),
            ]);

            return true;
        }
        if ($outcome === TaskTurnOutcome::Approved && $this->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            // Orbit commits the whole workspace, so the approval waits until the implementer stops changing it.
            return true;
        }
        if ($outcome === TaskTurnOutcome::ChangesRequested && $this->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            return true;
        }
        if ($receipt instanceof TaskComment
            && in_array($outcome, [TaskTurnOutcome::Blocked, TaskTurnOutcome::ChangesRequested, TaskTurnOutcome::Approved], true)) {
            $decision = $this->reviewWorkspaceDecision($group, $task, $reviewer, $receipt, $observation);
            if ($decision === 'orbit_commit') {
                try {
                    $recovered = $this->recoveredCommit($task, $this->workspaceSnapshot($group));
                } catch (TaskCheckException $exception) {
                    $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                    return true;
                }
                if ($recovered === null) {
                    $this->recordCommunicationFailure($task, $group, 'Orbit could not commit the approved subtask.');

                    return true;
                }
                $receipt->update(['commit_sha' => $recovered]);
                $this->publishApprovedCommit($group, $task, $receipt);

                return true;
            }
            if ($decision !== 'apply') {
                return true;
            }
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::Approved && $this->committedApproval($receipt)) {
            // The commit is already stored and the workspace still holds it. Retry the push only; do not commit again.
            $this->publishApprovedCommit($group, $task, $receipt);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::Blocked) {
            $this->requestAssistance($task, $group, TaskAssistance::ReviewerBlockedPrefix.$receipt->body, $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['review_handled_comment_id' => $receipt->id], $receipt);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::ChangesRequested && $task->isFinalReview()) {
            $this->applyFinalReviewFindings($group, $task, $receipt);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::ChangesRequested) {
            $this->relayFindings($group, $task, $observation, $receipt);

            return true;
        }

        $instance = $group->taskable;
        $pullRequest = null;
        $items = [$this->receiptItem($read, $receipt)];
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::Approved) {
            $onBranch = $instance instanceof Instance && $this->workspace->currentBranch($instance) === 'task-'.$group->id;
            $items[] = new TaskRubricItem('branch', $onBranch, 'The workspace branch is not task-'.$group->id.'. Switch back to it.');
            $confirmation = $this->confirmationItem($task, $receipt, TaskThreadRole::Reviewer);
            if ($confirmation instanceof TaskRubricItem) {
                $items[] = $confirmation;
            }
            if ($task->opensPullRequest()) {
                $pullRequest = TaskTurnPullRequest::fromArray($receipt->pull_request);
                $items[] = new TaskRubricItem('pull_request_fields', $pullRequest instanceof TaskTurnPullRequest, 'The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking.');
            }
        }
        $waiting = $this->waitingItem($reviewer);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }
        if ($this->failedItems($items) === [] && $pullRequest instanceof TaskTurnPullRequest) {
            try {
                $missing = $this->coverage->missing(
                    $group,
                    $pullRequest,
                    $receipt->id,
                    $pullRequest->changes,
                );
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

        if ($task->isFinalReview()) {
            // ADR 0203: a final review commits nothing. Its approval names the HEAD it reviewed, and only that HEAD is pushed.
            $head = $task->review_workspace_head;
            if (! is_string($head) || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $head) !== 1) {
                $this->recordCommunicationFailure($task, $group, 'Orbit could not read the head the final review approved.');

                return true;
            }
            $receipt->update(['commit_sha' => $head]);
            TaskFinalReview::log($group, 'final review approved', ['subtask_id' => $task->id, 'comment_id' => $receipt->id, 'commit_sha' => $head]);
            $this->publishApprovedCommit($group, $task, $receipt);

            return true;
        }
        $commit = $instance instanceof Instance ? $this->signer->commit($instance, $task->title."\n\n".$receipt->body) : null;
        if ($commit === null && $instance instanceof Instance) {
            // The commit can land and the SHA response can still be lost. The parent and tree identify Orbit's commit.
            try {
                $commit = $this->recoveredCommit($task, $this->workspaceSnapshot($group));
            } catch (TaskCheckException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
        }
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
    private function retryCommittedApproval(Task $group, Task $task): bool
    {
        return TaskExecutionHold::run($group, fn (): bool => $this->retryAdmittedApproval($group, $task)) ?? true;
    }

    private function retryAdmittedApproval(Task $group, Task $task): bool
    {
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        if (! $receipt instanceof TaskComment || $this->receiptOutcome($receipt) !== TaskTurnOutcome::Approved || ! $this->committedApproval($receipt)) {
            return false;
        }
        if (! $this->retryIsDue($this->publicationBackoffKey($task), 'approved publication')) {
            return true;
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
     * Pushes the stored commit, then opens the pull request when this approval is the one that publishes it.
     * The open pushes that commit again. The refspec names the commit, never HEAD, and is not a force push.
     * When the pull request URL is already stored, the push updates that pull request and Orbit does not open another (ADR 0164).
     * A failed push or open leaves the subtask in review and keeps commit_sha.
     */
    private function publishApprovedCommit(Task $group, Task $task, TaskComment $receipt): void
    {
        $commit = $receipt->commit_sha;
        if (! is_string($commit) || $commit === '') {
            $this->recordCommunicationFailure($task, $group, 'Orbit could not commit the approved subtask.');

            return;
        }
        if ($task->isFinalReview()) {
            $this->publishReviewedHead($group, $task, $receipt, $commit);

            return;
        }
        if ($group->reviewsBeforePush()) {
            // ADR 0203: the approved commit stays in the workspace until a final review approves the whole branch.
            $this->finishApproval($group, $task, $receipt);

            return;
        }
        if (! $this->retryIsDue($this->publicationBackoffKey($task), 'approved publication')) {
            return;
        }
        if (! $this->pullRequestStillOpen($group, $task, $commit)) {
            return;
        }
        try {
            $this->publisher->push($group, $commit);
        } catch (TaskPullRequestException $exception) {
            $this->failPublication($group, $task, $exception->getMessage());

            return;
        }

        if ($task->opensPullRequest()) {
            $pullRequest = TaskTurnPullRequest::fromArray($receipt->pull_request);
            if (! $pullRequest instanceof TaskTurnPullRequest) {
                $this->recordCommunicationFailure($task, $group, 'The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking.');

                return;
            }
            try {
                $url = $this->publisher->publish($group, TaskPullRequestDescription::render($pullRequest, $group->tasks()->whereNotIn('status', [TaskStatus::Cancelled, TaskStatus::Failed])->count(), $group->project->taskCheckCommand()), $commit);
            } catch (TaskPullRequestException $exception) {
                $this->failPublication($group, $task, $exception->getMessage());

                return;
            }
            $group->update(['pr_url' => $url]);
        }

        $this->finishApproval($group, $task, $receipt);
    }

    /** Marks a published, or held, approval handled and completes the subtask. */
    private function finishApproval(Task $group, Task $task, TaskComment $receipt): void
    {
        $this->rememberBackoff($this->publicationBackoffKey($task), null, 'approved publication');
        TaskQuestions::answerPending($task, $receipt);
        $task->update([
            'review_handled_comment_id' => $receipt->id,
            'communication_failures' => 0,
        ]);
        $this->clearPublicationAssistance($task, $group);
        $this->acceptReview($task);
    }

    /**
     * ADR 0203: records the HEAD the final review approved as fully reviewed, then pushes it, opens the pull
     * request when none exists, and approves an incoming pull request on GitHub. A head that is already the
     * incoming pull request head is not pushed. A failed step keeps the final review open and retries it.
     */
    private function publishReviewedHead(Task $group, Task $task, TaskComment $receipt, string $commit): void
    {
        if (! $this->retryIsDue($this->publicationBackoffKey($task), 'approved publication')) {
            return;
        }
        $incoming = $group->reviewsIncomingPullRequest();
        $pullRequestHead = $incoming && is_string($task->fixup_head_sha) && $task->fixup_head_sha === $commit;
        $reviewed = TaskReviewedCommit::query()->firstOrCreate(
            ['task_id' => $group->id, 'sha' => $commit],
            ['source' => $pullRequestHead ? TaskReviewedCommitSource::PullRequestReview : TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $task->id],
        );
        if (! $pullRequestHead && $reviewed->pushed_at === null) {
            if (! $this->pullRequestStillOpen($group, $task, $commit)) {
                return;
            }
            try {
                $this->publisher->push($group, $commit);
                if ($task->opensPullRequest()) {
                    $pullRequest = TaskTurnPullRequest::fromArray($receipt->pull_request);
                    if (! $pullRequest instanceof TaskTurnPullRequest) {
                        $this->recordCommunicationFailure($task, $group, 'The final review that opens the pull request needs --pr-summary, --pr-change, and --pr-breaking.');

                        return;
                    }
                    $delivered = $group->tasks()->whereNotIn('status', [TaskStatus::Cancelled, TaskStatus::Failed])->withoutFinalReviews()->count();
                    $url = $this->publisher->publish($group, TaskPullRequestDescription::render($pullRequest, $delivered, $group->project->taskCheckCommand()), $commit);
                    $group->update(['pr_url' => $url]);
                }
            } catch (TaskPullRequestException $exception) {
                $moved = $incoming ? $this->pullRequestWatcher->health($group)?->headSha : null;
                if (is_string($moved) && $moved !== $commit && ! TaskFinalReview::isReviewed($group, $moved)) {
                    $this->mergeAuthorPush($group, $task, $receipt, $moved);

                    return;
                }
                $this->failPublication($group, $task, $exception->getMessage());

                return;
            }
            $reviewed->update(['pushed_at' => now()]);
            TaskFinalReview::log($group, 'reviewed commit pushed', ['subtask_id' => $task->id, 'commit_sha' => $commit, 'pull_request' => $group->pr_url]);
        }
        if ($incoming && $reviewed->github_review_id === null) {
            try {
                $reviewId = $this->merger->review($group, $commit, GitHubReviewEvent::Approve, $this->approvalReviewBody($receipt, $commit));
            } catch (TaskPullRequestException $exception) {
                $this->failPublication($group, $task, $exception->getMessage());

                return;
            }
            $reviewed->update(['github_review_id' => $reviewId]);
            TaskFinalReview::log($group, 'pull request approved', ['subtask_id' => $task->id, 'commit_sha' => $commit, 'review_id' => $reviewId, 'pull_request' => $group->pr_url]);
        }

        $this->finishApproval($group, $task, $receipt);
    }

    private function approvalReviewBody(TaskComment $receipt, string $commit): string
    {
        return "Orbit reviewed the whole pull request at {$commit} and approves it.\n\n".mb_substr(trim($receipt->body), 0, 60000);
    }

    /**
     * ADR 0203: a final review requested changes. On an incoming pull request whose head it reviewed, Orbit posts
     * the findings as a `REQUEST_CHANGES` review first. Then, in one transaction, it completes the final review and
     * starts a fixup subtask with the findings. The fourth set of findings in one window asks for assistance.
     */
    private function applyFinalReviewFindings(Task $group, Task $task, TaskComment $receipt): void
    {
        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        $count = TaskFinalReview::fixupsInWindow($group->tasks);
        if ($count >= TaskFinalReview::FixupLimit) {
            $this->stopAtFinalReviewCap($group, $task, $receipt, $count);

            return;
        }
        $head = $task->review_workspace_head;
        if ($group->reviewsIncomingPullRequest() && is_string($head) && $head !== '' && $head === $task->fixup_head_sha) {
            $key = 'tasks.final-review-request-changes.'.$receipt->id;
            if (Cache::get($key) === null) {
                try {
                    $reviewId = $this->merger->review($group, $head, GitHubReviewEvent::RequestChanges, "Orbit reviewed the whole pull request at {$head} and requests changes. Orbit applies them itself.\n\n".mb_substr(trim($receipt->body), 0, 60000));
                } catch (TaskPullRequestException $exception) {
                    $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                    return;
                }
                Cache::forever($key, $reviewId);
                TaskFinalReview::log($group, 'pull request changes requested', ['subtask_id' => $task->id, 'commit_sha' => $head, 'review_id' => $reviewId, 'pull_request' => $group->pr_url]);
            }
        }

        $fixup = $this->replaceFinalReviewWithFixup($group, $task, $receipt, TaskFinalReview::FixupTitle, TaskFinalReview::fixupBrief($task, $receipt));
        if (! $fixup instanceof Task) {
            return;
        }
        TaskFinalReview::log($group, 'final review requested changes', ['subtask_id' => $task->id, 'comment_id' => $receipt->id, 'fixup_id' => $fixup->id]);
        $this->recordSubtaskStart($fixup);
        $this->assignImplementer($fixup);
    }

    /**
     * Completes the final review and starts a final-review fixup in one transaction. The caller starts its implementer.
     */
    private function replaceFinalReviewWithFixup(Task $group, Task $task, TaskComment $receipt, string $title, string $brief): ?Task
    {
        return DB::transaction(function () use ($group, $task, $receipt, $title, $brief): ?Task {
            $lockedGroup = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)->lockForUpdate()->findOrFail($group->id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (TaskExecutionHold::active($lockedGroup) || $locked->status !== TaskStatus::Reviewing || $locked->review_handled_comment_id === $receipt->id) {
                return null;
            }
            $lockedGroup->loadMissing('project');
            $fixup = Task::query()->create([
                'parent_id' => $lockedGroup->id,
                'position' => TaskFinalReview::nextPosition($this->lockedTasks($lockedGroup)),
                'title' => $title,
                'brief' => $brief,
                'deliverables' => TaskFinalReview::fixupDeliverables($lockedGroup->project->taskCheckCommand()),
                'fixup_problem' => TaskFinalReview::FixupProblem,
                'status' => TaskStatus::Todo,
            ]);
            TaskQuestions::answerPending($locked, $receipt);
            $locked->update([
                'status' => TaskStatus::Completed,
                'settled_at' => $locked->settled_at ?? now(),
                'review_handled_comment_id' => $receipt->id,
                'communication_failures' => 0,
            ]);
            $this->markRunning($fixup, $this->lockedTasks($lockedGroup));
            $lockedGroup->status = TaskGroupStatus::Running;
            $lockedGroup->save();

            return $fixup->fresh(['parent.tasks', 'parent.project', 'parent.taskable']) ?? $fixup;
        });
    }

    /**
     * The author pushed to an incoming pull request while Orbit's reviewed work waited, so Orbit's push is no
     * longer a fast-forward. Orbit completes the final review and starts a fixup that merges the author's commits.
     */
    private function mergeAuthorPush(Task $group, Task $task, TaskComment $receipt, string $head): void
    {
        $branch = TaskRemoteBranch::for($group);
        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        $count = TaskFinalReview::fixupsInWindow($group->tasks);
        if ($count >= TaskFinalReview::FixupLimit) {
            $this->stopAtFinalReviewCap($group, $task, $receipt, $count);

            return;
        }
        $fixup = $this->replaceFinalReviewWithFixup($group, $task, $receipt, 'Merge origin/'.$branch,
            'The pull request author pushed '.$head.' to '.$branch.' after Orbit reviewed its own work, so Orbit cannot push without forcing. '
            .'Merge origin/'.$branch.' into the task branch and resolve any conflicts. Do not rebase and do not force-push.');
        if (! $fixup instanceof Task) {
            return;
        }
        TaskFinalReview::log($group, 'pull request branch moved', ['subtask_id' => $task->id, 'head_sha' => $head, 'fixup_id' => $fixup->id]);
        $this->recordSubtaskStart($fixup);
        $this->assignImplementer($fixup);
    }

    /**
     * ADR 0203: the final review is complete and its findings wait for a person. The task settles and asks for
     * assistance. An operator subtask appended to it resumes the task and, when it completes, opens a new window.
     */
    private function stopAtFinalReviewCap(Task $group, Task $task, TaskComment $receipt, int $count): void
    {
        $reason = TaskFinalReview::FixupCapPrefix.'Orbit already appended '.$count.' fixups for final review findings in this window. Read the findings of subtask #'.$task->id.', then append a subtask with tasks:subtask:create, or complete the task.';
        $stopped = DB::transaction(function () use ($group, $task, $receipt, $reason): bool {
            $lockedGroup = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)->lockForUpdate()->findOrFail($group->id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (TaskExecutionHold::active($lockedGroup) || $locked->status !== TaskStatus::Reviewing || $locked->review_handled_comment_id === $receipt->id) {
                return false;
            }
            TaskQuestions::answerPending($locked, $receipt);
            $locked->update(['status' => TaskStatus::Completed, 'settled_at' => $locked->settled_at ?? now(), 'review_handled_comment_id' => $receipt->id, 'communication_failures' => 0]);
            $lockedGroup->status = TaskGroupStatus::Settling;
            $lockedGroup->save();

            return TaskAssistance::apply($lockedGroup, AssistanceKind::Failure, null, $reason);
        });
        TaskFinalReview::log($group, 'final review requested changes', ['subtask_id' => $task->id, 'comment_id' => $receipt->id, 'fixup_id' => null, 'reason' => $reason]);
        if ($stopped) {
            $this->coder->assistance($group, $reason);
        }
    }

    /**
     * ADR 0203: appends a final review when none is open and starts it. On an incoming pull request,
     * `$head` is the pull request head that the final review moves the workspace to.
     */
    private function beginFinalReview(Task $group, ?string $head = null): void
    {
        $appended = DB::transaction(function () use ($group, $head): bool {
            $locked = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)->lockForUpdate()->findOrFail($group->id);
            if (! in_array($locked->status, TaskGroupStatus::awaitingCompletion(), true) || TaskExecutionHold::active($locked)
                || ($locked->assistance_requested && TaskFinalReview::isCapReason($locked->assistance_reason))) {
                return false;
            }
            $tasks = $this->lockedTasks($locked);
            if ($tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))) {
                return false;
            }
            $locked->loadMissing('project');
            Task::query()->create([
                'parent_id' => $locked->id,
                'position' => TaskFinalReview::nextPosition($tasks),
                'type' => TaskType::FinalReview,
                'title' => TaskFinalReview::Title,
                'brief' => TaskFinalReview::brief($locked),
                'deliverables' => TaskFinalReview::deliverables(),
                'fixup_head_sha' => $head,
                'status' => TaskStatus::Todo,
            ]);

            return true;
        });
        if ($appended) {
            TaskFinalReview::log($group, 'final review appended', ['pull_request_head' => $head]);
            $this->resumeWaitingSubtask($group);
        }
    }

    /**
     * ADR 0203: a final review skips the implementer and the task check. On an incoming pull request without
     * unpushed approved work, the workspace first moves to the pull request head. The diff base is the merge
     * base with the default branch, so the reviewer sees the whole branch.
     */
    private function startFinalReview(Task $group, Task $task, bool $alreadyFetched): void
    {
        if ($task->status !== TaskStatus::Running) {
            return;
        }
        try {
            if (! $alreadyFetched) {
                $this->turnFetcher->fetch($group);
            }
            if ($group->reviewsIncomingPullRequest() && ! TaskFinalReview::hasUnreviewedWork($group)) {
                // The author can push before the review starts. Review the head the pull request has now.
                $current = $this->pullRequestWatcher->health($group)?->headSha;
                if (is_string($current) && $current !== '' && $current !== $task->fixup_head_sha) {
                    $task->update(['fixup_head_sha' => $current]);
                    if ($alreadyFetched) {
                        $this->turnFetcher->fetch($group);
                    }
                }
                $head = $task->fixup_head_sha;
                if (! is_string($head) || $head === '') {
                    throw new TaskPullRequestException('Orbit could not read the pull request head.');
                }
                $this->bases->moveTo($group, $head);
            }
            $base = $this->bases->mergeBase($group);
        } catch (Throwable $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $started = DB::transaction(function () use ($task, $base): bool {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedGroup = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)->lockForUpdate()->findOrFail($locked->parent_id);
            if (TaskExecutionHold::active($lockedGroup) || $locked->status !== TaskStatus::Running) {
                return false;
            }
            $locked->update(['status' => TaskStatus::Reviewing, 'subtask_start_commit' => $base, 'communication_failures' => 0]);
            $lockedGroup->update(['status' => TaskGroupStatus::Reviewing]);

            return true;
        });
        if ($started) {
            $this->clearCommunicationFailures($task);
            $this->nudgeReviewer($task->fresh() ?? $task, null);
        }
    }

    /**
     * ADR 0203: merges the pull request through the App when its head is one Orbit fully reviewed, the merge
     * check passed on that exact head, GitHub reports it mergeable, and no trusted account requests changes.
     * A head Orbit did not review is reviewed again on an incoming pull request, and asks for assistance on an
     * Orbit task branch. Each result is stored on the task, and each change of result is recorded in Activity.
     */
    private function mergeWhenReady(Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $reviews): void
    {
        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        $head = $health->headSha;
        if ($group->assistance_requested && TaskFinalReview::isUnreviewedHeadReason($group->assistance_reason)
            && is_string($head) && TaskFinalReview::isReviewed($group, $head)) {
            // The head is reviewed again, so the request that held the merge no longer applies.
            $group->update(TaskAssistance::cleared());
        }
        if (! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) || $group->assistance_requested
            || $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))
            || TaskFinalReview::hasUnreviewedWork($group)) {
            return;
        }
        if (! is_string($head) || $head === '') {
            $this->recordMerge($group, TaskMergeStatus::Waiting, 'GitHub did not report the pull request head.');

            return;
        }
        if (! TaskFinalReview::isReviewed($group, $head)) {
            if ($group->reviewsIncomingPullRequest()) {
                $this->recordMerge($group, TaskMergeStatus::Waiting, 'Orbit reviews the new head '.$head.'.');
                $this->beginFinalReview($group, $head);

                return;
            }
            $reason = TaskFinalReview::UnreviewedHeadPrefix.'someone other than Orbit pushed '.$head.' to '.TaskRemoteBranch::for($group).'. Orbit does not merge it. Append a subtask so Orbit reviews and pushes the branch again, or complete the task.';
            $this->recordMerge($group, TaskMergeStatus::Refused, $reason);
            if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason)) {
                $this->coder->assistance($group, $reason);
            }

            return;
        }
        $default = $group->project->default_branch;
        if (! is_string($health->baseRef) || $health->baseRef !== $default) {
            $this->recordMerge($group, TaskMergeStatus::Refused, 'The pull request base is '.($health->baseRef ?? 'unknown').', not the default branch '.($default ?? 'unknown').'. Orbit merges only into the default branch it reviewed against.');

            return;
        }
        if ($health->conflicts || $health->mergeable !== true) {
            $this->recordMerge($group, TaskMergeStatus::Waiting, 'GitHub has not reported '.$group->pr_url.' mergeable.');

            return;
        }
        // One full evaluation per head and minute. The pull request read above already runs every tick.
        if (! Cache::add('tasks.merge-gate.'.$group->id.'.'.$head, true, 60)) {
            return;
        }
        $check = $group->project->mergeCheckName() ?? '';
        $state = $this->merger->requiredCheck($group, $head, $check);
        if ($state !== RequiredCheckState::Passed) {
            $this->recordMerge($group, $state === RequiredCheckState::Failed ? TaskMergeStatus::Refused : TaskMergeStatus::Waiting, match ($state) {
                RequiredCheckState::Pending => 'Check '.$check.' is still running on '.$head.'.',
                RequiredCheckState::Missing => 'Check '.$check.' has no run on '.$head.' yet.',
                RequiredCheckState::Failed => 'Check '.$check.' did not pass on '.$head.', or a run of that name came from an App other than github-actions.',
                default => 'Orbit could not read check '.$check.' on '.$head.'.',
            });

            return;
        }
        $blocked = $this->trustedChangesRequested($reviews);
        if ($blocked !== null) {
            $this->recordMerge($group, $reviews?->status === TaskReviewReadStatus::Complete ? TaskMergeStatus::Refused : TaskMergeStatus::Waiting, $blocked);

            return;
        }
        try {
            $result = $this->merger->merge($group, $head);
        } catch (TaskPullRequestException $exception) {
            $this->recordMerge($group, TaskMergeStatus::Waiting, $exception->getMessage());

            return;
        }
        if (! $result->merged) {
            $this->recordMerge($group, TaskMergeStatus::Refused, 'GitHub refused the merge of '.$head.' ('.$result->status.'): '.($result->message !== '' ? $result->message : 'no message').'.');
            if ($health->behind) {
                // A branch protection rule can require an up-to-date branch. Orbit merges the base itself.
                $this->appendBaseMerge($group, (string) $default);
            }

            return;
        }
        $group->update(['merge_status' => TaskMergeStatus::Merged, 'merge_reason' => null, 'merged_sha' => $result->sha]);
        TaskFinalReview::log($group, 'pull request merged', ['pull_request' => $group->pr_url, 'head_sha' => $head, 'merge_sha' => $result->sha]);
        $this->broadcasts->groupChanged($group->id);
    }

    /**
     * ADR 0203: GitHub refused to merge a branch that is behind its base. Orbit appends a final-review fixup that
     * merges the base, so a final review and a reviewed push follow. It never asks GitHub to update the branch.
     */
    private function appendBaseMerge(Task $group, string $base): void
    {
        if (TaskFinalReview::fixupsInWindow($group->tasks) >= TaskFinalReview::FixupLimit) {
            $reason = TaskFinalReview::FixupCapPrefix.'GitHub refused to merge a branch behind '.$base.', and Orbit already appended '.TaskFinalReview::FixupLimit.' final-review fixups in this window. Append a subtask with tasks:subtask:create, or complete the task.';
            if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason)) {
                $this->coder->assistance($group, $reason);
            }

            return;
        }
        $appended = DB::transaction(function () use ($group, $base): bool {
            $locked = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)->lockForUpdate()->findOrFail($group->id);
            $tasks = $this->lockedTasks($locked);
            if (! in_array($locked->status, TaskGroupStatus::awaitingCompletion(), true) || TaskExecutionHold::active($locked) || $locked->assistance_requested
                || $tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))) {
                return false;
            }
            $locked->loadMissing('project');
            Task::query()->create([
                'parent_id' => $locked->id,
                'position' => TaskFinalReview::nextPosition($tasks),
                'title' => 'Merge origin/'.$base,
                'brief' => 'GitHub refused to merge the pull request because its branch is behind '.$base.'. Merge origin/'.$base.' into the task branch and resolve any conflicts. Do not rebase and do not force-push.',
                'deliverables' => TaskFinalReview::fixupDeliverables($locked->project->taskCheckCommand()),
                'fixup_problem' => TaskFinalReview::FixupProblem,
                'status' => TaskStatus::Todo,
            ]);

            return true;
        });
        if ($appended) {
            TaskFinalReview::log($group, 'base merge appended', ['base' => $base, 'pull_request' => $group->pr_url]);
            $this->resumeWaitingSubtask($group);
        }
    }

    /** The reason a trusted request for changes, or an incomplete review read, blocks the merge. Null when nothing blocks. */
    private function trustedChangesRequested(?TaskReviewObservation $reviews): ?string
    {
        if (! $reviews instanceof TaskReviewObservation) {
            return 'Orbit waits to read the pull request reviews again.';
        }
        if ($reviews->status === TaskReviewReadStatus::Disabled) {
            return null;
        }
        if ($reviews->status !== TaskReviewReadStatus::Complete) {
            return 'Orbit could not read every review of the pull request ('.$reviews->status->value.').';
        }
        foreach ($reviews->selection->effective ?? [] as $review) {
            if ($review->state === GitHubReviewState::ChangesRequested) {
                return 'Trusted reviewer '.$review->reviewerLogin.' requested changes in review '.$review->id.'. They must approve or dismiss it first.';
            }
        }

        return null;
    }

    /** Stores the merge gate's result. A changed result is recorded in Activity once. */
    private function recordMerge(Task $group, TaskMergeStatus $status, string $reason): void
    {
        if ($group->merge_status === $status && $group->merge_reason === $reason) {
            return;
        }
        $group->update(['merge_status' => $status, 'merge_reason' => $reason]);
        TaskFinalReview::log($group, 'merge '.$status->value, ['pull_request' => $group->pr_url, 'reason' => $reason]);
    }

    /**
     * ADR 0164: before a commit is pushed to a stored pull request, re-read its state. A merged or closed
     * pull request would never carry the commit, so Orbit does not push it and asks for assistance naming
     * it. An unreadable state is a publication failure that waits out the backoff.
     */
    private function pullRequestStillOpen(Task $group, Task $task, string $commit): bool
    {
        if (! is_string($group->pr_url) || $group->pr_url === '') {
            return true;
        }
        $state = $this->pullRequestWatcher->status($group);
        if ($state === 'open') {
            return true;
        }
        if ($state === null) {
            $this->failPublication($group, $task, 'Orbit could not read the state of '.$group->pr_url.'.');

            return false;
        }

        $key = $this->publicationBackoffKey($task);
        $this->extendBackoff($key, $this->readBackoff($key, 'approved publication'), 'approved publication');
        $this->requestAssistance($task, $group, self::OrphanedCommitPrefix.'Orbit did not push commit '.$commit.' of subtask #'.$task->id.' because '.$group->pr_url.' is already '.$state.'. Push that commit to a new branch and open a pull request, or cancel the group.');

        return false;
    }

    /** Whether the group waits for an operator because an approved commit missed its merged pull request. */
    private function orphanedCommit(Task $group): bool
    {
        return $group->assistance_requested && is_string($group->assistance_reason)
            && str_starts_with($group->assistance_reason, self::OrphanedCommitPrefix);
    }

    /**
     * ADR 0164: returning to settling and every merged cleanup tick revalidate the pull request. When its
     * head is not the latest approved commit, that commit missed the merge. The group asks for assistance
     * naming the commit and is not completed, so its workspace stays.
     */
    private function checkReturningPullRequest(Task $group, ?TaskPullRequestHealth $health = null): bool
    {
        $health ??= $this->pullRequestWatcher->health($group);
        if (! $health instanceof TaskPullRequestHealth || $health->state !== 'merged' || $health->headSha === null) {
            return false;
        }
        $commit = TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');
        if (! is_string($commit) || $commit === '' || $commit === $health->headSha || $this->orphanedCommit($group)) {
            return $this->orphanedCommit($group);
        }

        $reason = self::OrphanedCommitPrefix.'Commit '.$commit.' reached task-'.$group->id.' after '.$group->pr_url.' merged at '.$health->headSha.'. Open a pull request for task-'.$group->id.', or complete the group.';
        if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true)) {
            $this->coder->assistance($group, $reason);
        }

        // A direction request keeps its question, but the missed approval still protects the workspace.
        return true;
    }

    /** A failed push or open waits out the backoff and asks for assistance on the fifth failure. */
    private function failPublication(Task $group, Task $task, string $reason): void
    {
        $key = $this->publicationBackoffKey($task);
        $this->extendBackoff($key, $this->readBackoff($key, 'approved publication'), 'approved publication');
        $this->recordCommunicationFailure($task, $group, self::PublicationFailedPrefix.$reason);
    }

    /** Clears the publication failure only. A blocked question or any other cause stays on the subtask and the group. */
    private function clearPublicationAssistance(Task $task, Task $group): void
    {
        $task->refresh();
        $group->refresh();
        if (is_string($task->assistance_reason) && str_starts_with($task->assistance_reason, self::PublicationFailedPrefix)) {
            $task->update(TaskAssistance::cleared());
        }
        if (is_string($group->assistance_reason) && str_starts_with($group->assistance_reason, self::PublicationFailedPrefix)) {
            $group->update(TaskAssistance::cleared());
        }
    }

    private function publicationBackoffKey(Task $task): string
    {
        return 'tasks.approved-publication.'.$task->id;
    }

    private function relayFindings(Task $group, Task $task, TaskSessionObservation $observation, TaskComment $findings): void
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
            if (! $this->prepareTurn($group, $task, TaskThreadRole::Implementer, $implementer->threadId)) {
                return;
            }
            $this->actor->relayReviewBody($group, $implementer, $findings->body);
        } catch (AgentDriverException|TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        TaskQuestions::answerPending($task, $findings);
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
    private function implementerItems(Task $task, TaskThreadObservation $thread, ?TaskTurnReceipt $read, ?TaskComment $receipt): array
    {
        $items = [
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
     * ADR 0133: a receipt confirms every deliverable it must, as the turn command requires. A hand-written
     * receipt that misses one fails the `deliverables` item.
     */
    private function confirmationItem(Task $task, ?TaskComment $receipt, TaskThreadRole $role): ?TaskRubricItem
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === [] || ! $receipt instanceof TaskComment) {
            return null;
        }
        $missing = TaskDeliverableVerifier::unconfirmed($deliverables, $receipt->deliverables ?? [], $role);

        return new TaskRubricItem('deliverables', $missing === [], $missing === [] ? '' : 'The turn receipt does not confirm the deliverables '.implode(', ', $missing).'. Pass --deliverable=ID=evidence for each one.');
    }

    /**
     * @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>, test_base?: string}|null
     */
    private function handoffCheck(Task $task): ?array
    {
        $deliverables = $this->deliverableCheck($task);
        $group = $task->parent;
        $start = $task->subtask_start_commit;
        if ($group->project->slug === 'orbit' && is_string($start)
            && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $start) === 1
            && $group->tasks()->where('position', '>', $task->position)->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->exists()) {
            return [...($deliverables ?? ['start' => $start, 'commands' => []]), 'test_base' => $start];
        }

        return $deliverables;
    }

    /**
     * ADR 0133: what the handoff check needs to record deliverable evidence, or null for a subtask without deliverables.
     * ADR 0163: a command with fails_on_base true also runs against the start commit with its paths overlaid.
     *
     * @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>}|null
     */
    private function deliverableCheck(Task $task): ?array
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === []) {
            return null;
        }
        $commands = [];
        foreach ($deliverables as $deliverable) {
            if ($deliverable->type !== TaskDeliverableType::Command) {
                continue;
            }
            $command = ['id' => $deliverable->id, 'command' => $deliverable->command, 'directory' => TaskDeliverable::relative($deliverable->directory) ?: '.'];
            if ($deliverable->fails_on_base) {
                $command['fails_on_base'] = true;
                $command['paths'] = $deliverable->paths;
            }
            $commands[] = $command;
        }

        $start = TaskReviewBase::commit($task);

        return ['start' => $start !== '' ? $start : null, 'commands' => $commands];
    }

    private function receiptItem(?TaskTurnReceipt $read, ?TaskComment $receipt): TaskRubricItem
    {
        if ($receipt instanceof TaskComment) {
            return new TaskRubricItem('turn_receipt', true, '');
        }

        return new TaskRubricItem('turn_receipt', false, $read instanceof TaskTurnReceipt ? 'The turn receipt was not valid for this turn.' : 'No turn receipt was found.');
    }

    /**
     * Stores a receipt that fits the turn as a comment, then removes the file. A crash before the
     * removal reads the receipt again, and its hash matches the stored comment.
     *
     * @throws TaskTurnReceiptException
     */
    private function collectReceipt(Task $group, Task $task, TaskThreadRole $role): ?TaskTurnReceipt
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskTurnReceiptException('The task workspace is unavailable.');
        }
        $actingThreadId = $this->actingThreadId($group, $task, $role);
        $receipt = $this->receipts->read($instance, $actingThreadId);
        if (! $receipt instanceof TaskTurnReceipt || ! $this->receiptMatchesActingThread($receipt, $actingThreadId)) {
            return null;
        }
        if ($receipt->outcome === TaskTurnOutcome::TopologyRequested) {
            $existing = TaskComment::query()->where('task_id', $task->id)->where('receipt_hash', $receipt->hash)->first();
            if (! $existing instanceof TaskComment) {
                TaskComment::query()->create([
                    'task_id' => $task->id,
                    'task_group_id' => $group->id,
                    'agent_thread_id' => $actingThreadId,
                    'receipt_hash' => $receipt->hash,
                    'completion_attempt' => $task->completion_attempt,
                    'review_attempt' => $role === TaskThreadRole::Reviewer ? $task->review_attempt : null,
                    'type' => TaskCommentType::TopologyRequested,
                    'body' => $receipt->summary."\n\n".$this->topologyReply($instance, $group, $task, $role),
                    'author' => $role->value,
                    'posted_at' => now(),
                ]);
            }
        } elseif ($receipt->outcome instanceof TaskTurnOutcome && $receipt->fits($role)) {
            TaskComment::query()->firstOrCreate(['task_id' => $task->id, 'receipt_hash' => $receipt->hash], [
                'task_group_id' => $group->id,
                'agent_thread_id' => $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id,
                'completion_attempt' => $task->completion_attempt,
                'review_attempt' => $role === TaskThreadRole::Reviewer ? $task->review_attempt : null,
                'type' => $receipt->outcome->commentType(),
                'body' => $receipt->body(),
                'pull_request' => $receipt->pullRequest?->toArray(),
                'deliverables' => $receipt->deliverables === [] ? null : $receipt->deliverables,
                'cause' => $receipt->cause,
                'author' => $role->value,
                'posted_at' => now(),
            ]);
        }
        $this->receipts->clear($instance, $receipt);

        return $receipt;
    }

    private function topologyReply(Instance $instance, Task $group, Task $task, TaskThreadRole $role): string
    {
        if ($role !== TaskThreadRole::Reviewer) {
            return 'Topology request refused. Ask the reviewer through a blocked consult; only the reviewer can request a topology.';
        }
        if ($group->task_compute === TaskCompute::Vm && $group->project->slug !== 'orbit') {
            return 'Topology request refused. Project VM groups use their own Project workspace.';
        }
        if ($group->task_compute === TaskCompute::Vm || $instance->task_sandbox_id !== null) {
            $task->update(['topology' => TaskTopology::from(array_values(array_unique([...($task->topology ?? []), 'app-dev', 'app-prod'])))]);

            return 'The sandbox workload topology request is recorded. Orbit verifies its private Gateway before resuming this turn.';
        }
        try {
            return $this->topology->acquire($instance, $group->id)
                ? 'Topology TASK-'.$group->id.' is ready.'
                : 'Topology TASK-'.$group->id.' is already held.';
        } catch (Throwable $exception) {
            return 'Topology TASK-'.$group->id.' acquisition failed: '.$exception->getMessage().' A missing topology does not block approval. Continue without operator assistance for this failure.';
        }
    }

    /** A resource request resumes this thread without answering a question or advancing the subtask. */
    private function resumeTopologyRequest(Task $group, Task $task, TaskThreadObservation $thread, ?TaskComment $receipt): bool
    {
        if (! $receipt instanceof TaskComment || $this->receiptOutcome($receipt) !== TaskTurnOutcome::TopologyRequested) {
            return false;
        }
        // Reserve before sending. A retry must not mistake the resumed turn for the requesting turn.
        $resume = $receipt->topology_resume;
        if ($resume === null) {
            $resume = ['source_turn_id' => $thread->turnId];
            $receipt->update(['topology_resume' => $resume]);
        }
        $sourceTurn = $resume['source_turn_id'];
        $key = 'topology-request-'.$receipt->id;
        $accepted = $thread->turnId === $key
            || array_any($thread->recentMessages, static fn (array $message): bool => $message['id'] === $key);
        $superseded = is_string($thread->turnId) && $thread->turnId !== '' && $thread->turnId !== $sourceTurn;
        if ($accepted || $superseded) {
            $this->finishTopologyResume($task, $thread->role, $receipt, $sourceTurn);

            return true;
        }
        try {
            if (! $this->prepareTurn($group, $task, $thread->role, $thread->threadId)) {
                return true;
            }
            $message = $receipt->body."\n\n".$this->actingInstructions($group, $task, $thread->role, $thread->threadId);
            $this->actor->resumeInterruptedTurn($group, $thread, $message, $key);
            $this->finishTopologyResume($task, $thread->role, $receipt, $sourceTurn);
        } catch (AgentDriverException|TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());
        }

        return true;
    }

    /** The completion gate always names the reserved source, never an already-stopped resumed turn. */
    private function finishTopologyResume(Task $task, TaskThreadRole $role, TaskComment $receipt, ?string $sourceTurn): void
    {
        $task->update($role === TaskThreadRole::Reviewer ? [
            'review_handled_comment_id' => $receipt->id,
            'review_notified_turn_id' => $sourceTurn,
            'communication_failures' => 0,
        ] : [
            'completion_handoff_comment_id' => $receipt->id,
            'completion_handoff_attempt' => $task->completion_attempt,
            'completion_handoff_turn_id' => $sourceTurn,
            'communication_failures' => 0,
        ]);
    }

    /** The Orbit id of the thread this phase acts as, or null when that thread has not been stored. */
    private function actingThreadId(Task $group, Task $task, TaskThreadRole $role): ?int
    {
        $id = $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id;

        return is_numeric($id) ? (int) $id : null;
    }

    /** A receipt applies only when it names the acting thread. An unbound receipt does not. */
    private function receiptMatchesActingThread(TaskTurnReceipt $receipt, ?int $actingThreadId): bool
    {
        return $actingThreadId === null || $receipt->threadId === $actingThreadId;
    }

    /**
     * Rewrites a legacy turn file for the acting thread and sends the bound turn command.
     * The unidentified receipt is not applied.
     */
    private function reissueLegacyTurn(Task $group, Task $task, TaskThreadObservation $thread): bool
    {
        $actingThreadId = $this->actingThreadId($group, $task, $thread->role);
        $instance = $group->taskable;
        if ($actingThreadId === null || $thread->threadId !== $actingThreadId || ! $instance instanceof Instance) {
            return false;
        }
        try {
            if (! $this->receipts->hasLegacyTurn($instance)) {
                return false;
            }
            if (! $this->prepareTurn($group, $task, $thread->role, $actingThreadId)) {
                return true;
            }
            $instructions = $this->actingInstructions($group, $task, $thread->role, $actingThreadId);
            $this->actor->remindRubric($group, $thread, 'Orbit bound this turn to its thread. '.$instructions);
        } catch (AgentDriverException|TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());
        }

        return true;
    }

    /** The instructions for the turn that is active now, not for a later review or relay. */
    private function actingInstructions(Task $group, Task $task, TaskThreadRole $role, ?int $threadId): string
    {
        if ($role === TaskThreadRole::Implementer) {
            return TaskTurnInstructions::implementer($task->deliverableList(), $group->project->taskCheckCommand(), $threadId);
        }
        if ($task->consult_comment_id !== null) {
            return TaskTurnInstructions::consult($threadId);
        }
        if ($task->direction_relay_comment_id !== null) {
            return TaskTurnInstructions::relay($threadId);
        }

        return TaskTurnInstructions::reviewer($task->opensPullRequest(), $task->deliverableList(), $threadId);
    }

    /**
     * Returns the latest stored receipt of this attempt that the scheduler has not acted on.
     */
    private function pendingReceipt(Task $task, TaskThreadRole $role): ?TaskComment
    {
        $implementer = $role === TaskThreadRole::Implementer;
        $receipt = $task->comments()
            ->whereNotNull('receipt_hash')
            ->where('author', $role->value)
            ->where($implementer ? 'completion_attempt' : 'review_attempt', $implementer ? $task->completion_attempt : $task->review_attempt)
            ->latest('id')
            ->first();
        $handled = $implementer ? $task->completion_handoff_comment_id : $task->review_handled_comment_id;

        return $receipt instanceof TaskComment && $receipt->id !== $handled ? $receipt : null;
    }

    private function receiptOutcome(TaskComment $receipt): ?TaskTurnOutcome
    {
        $type = $receipt->getRawOriginal('type');

        return TaskTurnOutcome::tryFrom(is_string($type) ? $type : '');
    }

    /** @throws TaskTurnReceiptException */
    private function prepareTurn(Task $group, Task $task, TaskThreadRole $role, ?int $threadId = null, bool $alreadyFetched = false): bool
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskTurnReceiptException('The task workspace is unavailable.');
        }
        if (! $alreadyFetched) {
            $this->turnFetcher->beforeTurn($group);
        }
        if (! $this->admitTopology($group, $task)) {
            return false;
        }
        $context = $role === TaskThreadRole::Reviewer ? $this->reviewPackets->reviewContext($task) : null;
        $consult = $role === TaskThreadRole::Reviewer && $task->consult_comment_id !== null;
        $relay = $role === TaskThreadRole::Reviewer && ! $consult && $task->direction_relay_comment_id !== null;
        $causeRequired = $role === TaskThreadRole::Reviewer && ! $consult && ! $relay && TaskQuestions::awaitsCause($task);
        $mode = $consult || $relay || $causeRequired ? new TaskTurnMode(consult: $consult, relay: $relay, causeRequired: $causeRequired) : null;
        $this->receipts->prepare($instance, $role, $role === TaskThreadRole::Reviewer && $task->opensPullRequest(), $task->deliverableList(), $threadId, $mode, $context);

        return true;
    }

    /** A preparation wait never consumes a spawn or communication-failure attempt. */
    private function admitTopology(Task $group, Task $task): bool
    {
        try {
            $this->topologyAdmission->prepare($group, $task);
        } catch (TaskCapacityException $exception) {
            $group->update(['capacity_wait_reason' => $exception->getMessage()]);

            return false;
        }
        if ($group->capacity_wait_reason !== null) {
            $group->update(['capacity_wait_reason' => null]);
        }

        return true;
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
    private function remindOrAssist(Task $group, Task $task, TaskThreadObservation $thread, array $items): bool
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
                if (! $this->prepareTurn($group, $task, $thread->role, $thread->threadId)) {
                    return false;
                }
                $mode = $implementer ? null : new TaskTurnMode(
                    consult: $task->consult_comment_id !== null,
                    relay: $task->consult_comment_id === null && $task->direction_relay_comment_id !== null,
                );
                $this->actor->remindRubric($group, $thread, TaskRubricReminder::compose($thread->role, $failures, ! $implementer && $task->opensPullRequest(), $task->deliverableList(), $group->project->taskCheckCommand(), $thread->threadId, $mode));
            } catch (AgentDriverException|TaskTurnReceiptException $exception) {
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

    private function recordCommunicationFailure(Task $task, Task $group, string $reason): void
    {
        $task->increment('communication_failures');
        $task->refresh();
        if ($task->communication_failures >= 5) {
            $this->requestAssistance($task, $group, $reason);
        }
    }

    /**
     * An implementer's blocked receipt asks the reviewer first. The third block in one attempt
     * asks for direction at once, with both earlier answers in the reason.
     */
    private function beginConsult(Task $group, Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        if (TaskQuestions::consultCount($task) >= TaskQuestions::ConsultLimit) {
            $this->requestAssistance($task, $group, $this->thirdBlockReason($task, $receipt), $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['completion_handoff_comment_id' => $receipt->id], $receipt);

            return;
        }

        if (! $this->spawner instanceof TaskAgentSpawner) {
            $this->recordCommunicationFailure($task, $group, self::ReviewRequestFailedReason.' (AgentSpawner).');

            return;
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }

        $this->recordConsultIntent($task, $receipt, $observation);
        $task->refresh();
        $this->dispatchConsult($group, $task, $receipt, $observation);
    }

    /** Records the consult for this blocked receipt before the send, so a lost response can be recovered. */
    private function recordConsultIntent(Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $own = $this->ownReviewer($task, $observation);
        $source = $own instanceof TaskThreadObservation && is_string($own->turnId) && $own->turnId !== '' ? $own->turnId : null;
        $key = $this->consultSendKey($receipt);
        DB::transaction(function () use ($task, $receipt, $key, $source): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->consult_comment_id !== null || $locked->assistance_requested) {
                return;
            }
            TaskQuestions::openConsult($locked, $receipt);
            $locked->update([
                'consult_comment_id' => $receipt->id,
                'completion_handoff_comment_id' => $receipt->id,
                'direction_answer_key' => $key,
                'direction_answer_source_turn_id' => $source,
                'communication_failures' => 0,
            ]);
        });
    }

    /** Sends the reserved consult once. An accepted key, or a later reviewer turn, is not sent again. */
    private function dispatchConsult(Task $group, Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $task->refresh();
        if (! $this->consultSendPending($task, $receipt)) {
            return;
        }
        $own = $this->ownReviewer($task, $observation);
        if ($own instanceof TaskThreadObservation && $this->relayAnswerAccepted($own, $task)) {
            $this->confirmConsultDispatch($group, $task, $own->threadId);

            return;
        }
        if ($own instanceof TaskThreadObservation && $this->isWorking($own)) {
            return;
        }
        $existing = $this->subtaskReviewer($task);
        if ($existing instanceof AgentThread && $own === null && $observation->available && $observation->threads === []) {
            return;
        }
        if (! $this->spawner instanceof TaskAgentSpawner) {
            $this->recordCommunicationFailure($task, $group, self::ReviewRequestFailedReason.' (AgentSpawner).');

            return;
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }

        try {
            $reserved = $this->spawner->reserveReviewer($task);
            $this->turnFetcher->beforeTurn($group);
            $this->receipts->prepare($instance, TaskThreadRole::Reviewer, false, $task->deliverableList(), $reserved, new TaskTurnMode(consult: true), $this->reviewPackets->reviewContext($task));
            $threadId = $this->spawner->consult($task, trim($receipt->body)."\n\n".TaskTurnInstructions::consult($reserved), $this->consultSendKey($receipt));
            if ($threadId === null) {
                throw new AgentDriverException('The reviewer conversation could not be started.');
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, self::ReviewRequestFailedReason.' ('.class_basename($exception).').');

            return;
        }
        $this->confirmConsultDispatch($group, $task, $threadId);
    }

    private function confirmConsultDispatch(Task $group, Task $task, ?int $threadId): void
    {
        DB::transaction(function () use ($group, $task, $threadId): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->consult_comment_id === null) {
                return;
            }
            $locked->update([
                'direction_answer_key' => null,
                'direction_answer_source_turn_id' => null,
                'communication_failures' => 0,
            ]);
            if (is_int($threadId)) {
                Task::topLevel()->whereKey($group->id)->update(['reviewer_agent_thread_id' => $threadId]);
            }
        });
    }

    private function consultSendPending(Task $task, TaskComment $receipt): bool
    {
        return $task->consult_comment_id === $receipt->id && $task->direction_answer_key === $this->consultSendKey($receipt);
    }

    /** The same blocked receipt always reserves the same reviewer send. */
    private function consultSendKey(TaskComment $receipt): string
    {
        return $this->relayAnswerKey($receipt, 'consult');
    }

    private function blockedConsultReceipt(Task $task): ?TaskComment
    {
        $id = $task->consult_comment_id;
        if (! is_int($id)) {
            return null;
        }
        $receipt = TaskComment::query()->find($id);

        return $receipt instanceof TaskComment ? $receipt : null;
    }

    /** This subtask's reviewer, not an earlier subtask's thread that happens to be observed first. */
    private function ownReviewer(Task $task, TaskSessionObservation $observation): ?TaskThreadObservation
    {
        $existing = $this->subtaskReviewer($task);
        if (! $existing instanceof AgentThread) {
            return null;
        }
        foreach ($observation->threads as $thread) {
            if ($thread->role === TaskThreadRole::Reviewer && $thread->threadId === $existing->id) {
                return $thread;
            }
        }

        return null;
    }

    /** Reads the consult receipt. `answered` continues the implementer. `blocked` asks for direction. */
    private function handleConsult(Task $group, Task $task): void
    {
        $observation = $this->observer->observe($group, $task);
        if (! $observation->available) {
            $decision = $this->unavailableDecision($group);
            if ($decision->action === TaskSessionNextAction::EscalateCoder) {
                $this->requestAssistance($task, $group, $decision->reason, $observation, AssistanceKind::Failure);
            }

            return;
        }
        $this->clearUnavailable($group);
        $blocked = $this->blockedConsultReceipt($task);
        if ($blocked instanceof TaskComment && $this->consultSendPending($task, $blocked)) {
            $this->dispatchConsult($group, $task, $blocked, $observation);
            $task->refresh();
            if ($this->consultSendPending($task, $blocked)) {
                return;
            }
            $observation = $this->observer->observe($group, $task);
        }
        $reviewer = $this->ownReviewer($task, $observation);
        if ($reviewer === null || $this->isWorking($reviewer)) {
            return;
        }
        $this->reconcilePiRestart($task, $reviewer);
        $state = AgentThreadState::tryFrom($reviewer->sessState);
        if ($state === AgentThreadState::Failed) {
            if ($this->resumePiServerRestart($task, $group, $reviewer) !== 'handled') {
                $this->requestAssistance($task, $group, 'The reviewer thread failed.', $observation, AssistanceKind::Failure);
            }

            return;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return;
        }
        if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return;
        }

        try {
            $read = $this->collectReceipt($group, $task, TaskThreadRole::Reviewer);
        } catch (TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        if ($this->resumeTopologyRequest($group, $task, $reviewer, $receipt)) {
            return;
        }
        $outcome = $receipt instanceof TaskComment ? $this->receiptOutcome($receipt) : null;
        if (! $receipt instanceof TaskComment || ! in_array($outcome, [TaskTurnOutcome::Answered, TaskTurnOutcome::Blocked], true)) {
            $this->remindOrAssist($group, $task, $reviewer, [
                $receipt instanceof TaskComment
                    ? new TaskRubricItem('turn_receipt', false, 'A consult needs --outcome=answered or --outcome=blocked, with --cause.')
                    : $this->receiptItem($read, $receipt),
            ]);

            return;
        }
        if (! $receipt->cause instanceof QuestionCause) {
            $this->remindOrAssist($group, $task, $reviewer, [
                new TaskRubricItem('turn_receipt', false, 'A consult needs --cause with one of: brief_unclear, contract_gap, scope, environment, missed_contract.'),
            ]);

            return;
        }
        if ($outcome === TaskTurnOutcome::Blocked) {
            $this->requestAssistance($task, $group, TaskAssistance::ReviewerBlockedPrefix.$receipt->body, $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['review_handled_comment_id' => $receipt->id], $receipt);

            return;
        }
        if (! TaskQuestions::answerConsult($task, $receipt)) {
            return;
        }
        $this->deliverConsultAnswer($group, $task, $observation, $receipt);
    }

    /** Sends the reviewer's answer to the implementer and keeps the same attempt. */
    private function deliverConsultAnswer(Task $group, Task $task, TaskSessionObservation $observation, TaskComment $receipt): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null || $this->isWorking($implementer)) {
            return;
        }
        $key = $this->relayAnswerKey($receipt, 'consult-answer');
        if ($task->direction_answer_key !== $key) {
            $sourceTurn = $implementer->turnId;
            $task->update([
                'direction_answer_key' => $key,
                'direction_answer_source_turn_id' => is_string($sourceTurn) && $sourceTurn !== '' ? $sourceTurn : null,
            ]);
            $task->refresh();
        }
        if ($this->relayAnswerAccepted($implementer, $task)) {
            $this->finishConsultDelivery($task, $receipt);

            return;
        }
        try {
            if (! $this->prepareTurn($group, $task, TaskThreadRole::Implementer, $implementer->threadId)) {
                return;
            }
            $this->actor->relayAnswer($group, $implementer, $this->correctionResume->continuationMessage($task, $receipt->body), $key);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, 'The consult answer could not be sent to the implementer. ('.class_basename($exception).').');

            return;
        }
        $this->finishConsultDelivery($task, $receipt);
    }

    private function finishConsultDelivery(Task $task, TaskComment $receipt): void
    {
        DB::transaction(function () use ($task, $receipt): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->consult_comment_id === null) {
                return;
            }
            $this->correctionResume->supersede($locked, $receipt);
            $locked->update([
                'consult_comment_id' => null,
                'direction_answer_key' => null,
                'direction_answer_source_turn_id' => null,
                'review_handled_comment_id' => $receipt->id,
                'communication_failures' => 0,
            ]);
        });
    }

    /** The third block in one attempt asks the operator, and the reason keeps both earlier answers. */
    private function thirdBlockReason(Task $task, TaskComment $receipt): string
    {
        $lines = array_map(
            static fn (string $answer): string => '- '.($answer !== '' ? $answer : '(no answer)'),
            TaskQuestions::earlierAnswers($task),
        );

        return TaskAssistance::ImplementerBlockedPrefix.$receipt->body."\n\nEarlier answers:\n".implode("\n", $lines);
    }

    /** A direction resolution is waiting to be relayed because the subtask has no reviewer yet. */
    private function pendingRelayResolution(Task $task): ?TaskComment
    {
        if ($task->assistance_kind !== AssistanceKind::Direction || $task->direction_relay_comment_id !== null) {
            return null;
        }
        $question = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->latest('id')
            ->first();
        if (! $question instanceof TaskQuestion) {
            return null;
        }
        if (is_int($question->resolution_comment_id)) {
            $held = TaskComment::query()->find($question->resolution_comment_id);
            $rawType = $held?->getRawOriginal('type');

            return $held instanceof TaskComment && $rawType === TaskCommentType::Resolution->value ? $held : null;
        }

        $openedCommentId = $question->opened_comment_id;
        $resolution = TaskComment::query()
            ->where('task_id', $task->id)
            ->where('type', TaskCommentType::Resolution)
            ->when(is_int($openedCommentId), static fn ($query) => $query->where('id', '>', $openedCommentId))
            ->latest('id')
            ->first();

        return $resolution instanceof TaskComment ? $resolution : null;
    }

    /** Starts the reviewer and sends the operator's resolution as a relay, not as a review packet. */
    private function beginDirectionRelay(Task $group, Task $task, TaskComment $resolution): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance || ! $this->spawner instanceof TaskAgentSpawner) {
            return;
        }

        try {
            $reserved = $this->spawner->reserveReviewer($task);
            $this->turnFetcher->beforeTurn($group);
            $this->receipts->prepare($instance, TaskThreadRole::Reviewer, $task->opensPullRequest(), $task->deliverableList(), $reserved, new TaskTurnMode(relay: true), $this->reviewPackets->reviewContext($task));
            $threadId = $this->spawner->relay($task, trim($resolution->body)."\n\n".TaskTurnInstructions::relay($reserved));
            if ($threadId === null) {
                throw new AgentDriverException('The reviewer conversation could not be started.');
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, self::ReviewRequestFailedReason.' ('.class_basename($exception).').');

            return;
        }

        DB::transaction(function () use ($task, $group, $resolution, $threadId): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! $locked->assistance_requested || $locked->assistance_kind !== AssistanceKind::Direction) {
                return;
            }
            $locked->update([
                ...TaskAssistance::cleared(),
                'communication_failures' => 0,
                'direction_relay_comment_id' => $resolution->id,
                'resolution_delivered_comment_id' => $resolution->id,
            ]);
            Task::topLevel()->whereKey($group->id)->update([
                ...TaskAssistance::cleared(),
                'reviewer_agent_thread_id' => $threadId,
            ]);
            TaskQuestions::attachResolution($locked, $resolution);
        });
    }

    /** Reads the relay receipt. `answered` records the operator's answer. `blocked` keeps the same question. */
    private function handleDirectionRelay(Task $group, Task $task): void
    {
        $observation = $this->observer->observe($group, $task);
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        if (! $observation->available || $reviewer === null || $this->isWorking($reviewer)) {
            return;
        }
        if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return;
        }

        try {
            $read = $this->collectReceipt($group, $task, TaskThreadRole::Reviewer);
        } catch (TaskTurnReceiptException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        if ($this->resumeTopologyRequest($group, $task, $reviewer, $receipt)) {
            return;
        }
        $outcome = $receipt instanceof TaskComment ? $this->receiptOutcome($receipt) : null;
        if (! $receipt instanceof TaskComment || ! in_array($outcome, [TaskTurnOutcome::Answered, TaskTurnOutcome::Blocked], true)) {
            if ($read instanceof TaskTurnReceipt || $receipt instanceof TaskComment) {
                $this->remindOrAssist($group, $task, $reviewer, [
                    new TaskRubricItem('turn_receipt', false, 'A relay needs --outcome=answered or --outcome=blocked, with --cause.'),
                ]);
            }

            return;
        }
        if (! $receipt->cause instanceof QuestionCause) {
            $this->remindOrAssist($group, $task, $reviewer, [
                new TaskRubricItem('turn_receipt', false, 'A relay needs --cause with one of: brief_unclear, contract_gap, scope, environment, missed_contract.'),
            ]);

            return;
        }
        if ($outcome === TaskTurnOutcome::Blocked) {
            $this->requestAssistance($task, $group, TaskAssistance::ReviewerBlockedPrefix.$receipt->body, $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['review_handled_comment_id' => $receipt->id], $receipt);

            return;
        }

        // The answer is durable immediately. The relay stays until the implementer has the summary.
        // A receipt-keyed reservation is reused, and an accepted or superseded turn is not sent again.
        TaskQuestions::answerPending($task, $receipt);
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null || $this->isWorking($implementer)) {
            return;
        }
        $key = $this->relayAnswerKey($receipt);
        if ($task->direction_answer_key !== $key) {
            $sourceTurn = $implementer->turnId;
            $task->update([
                'direction_answer_key' => $key,
                'direction_answer_source_turn_id' => is_string($sourceTurn) && $sourceTurn !== '' ? $sourceTurn : null,
            ]);
            $task->refresh();
        }
        if ($this->relayAnswerAccepted($implementer, $task)) {
            $this->finishRelayDelivery($task, $receipt);

            return;
        }
        try {
            if (! $this->prepareTurn($group, $task, TaskThreadRole::Implementer, $implementer->threadId)) {
                return;
            }
            $this->actor->relayAnswer($group, $implementer, $this->correctionResume->continuationMessage($task, $receipt->body), $key);
        } catch (Throwable $exception) {
            // Keep this receipt's key. An uncertain response may still have been accepted, and a
            // rejected T3 command id stays rejected, so the same id is not replaced on this failure.
            report($exception);
            $this->recordCommunicationFailure($task, $group, 'The relay answer could not be sent to the implementer. ('.class_basename($exception).').');

            return;
        }
        $this->finishRelayDelivery($task, $receipt);
    }

    /** The same reviewer receipt always reserves the same implementer send. */
    private function relayAnswerKey(TaskComment $receipt, string $prefix = 'relay-answer'): string
    {
        $hash = md5($prefix.':'.$receipt->id);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    /** An accepted key, its message, or a later implementer turn means the send already landed. */
    private function relayAnswerAccepted(TaskThreadObservation $implementer, Task $task): bool
    {
        $key = $task->direction_answer_key;
        if (! is_string($key) || $key === '') {
            return false;
        }
        $turnId = $implementer->turnId;
        if (is_string($turnId) && $turnId !== '' && $turnId === $key) {
            return true;
        }
        foreach ($implementer->recentMessages as $message) {
            if ($message['id'] === $key) {
                return true;
            }
        }
        $source = $task->direction_answer_source_turn_id;

        return is_string($turnId) && $turnId !== ''
            && is_string($source) && $source !== ''
            && $turnId !== $source;
    }

    private function finishRelayDelivery(Task $task, TaskComment $receipt): void
    {
        DB::transaction(function () use ($task, $receipt): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->direction_relay_comment_id === null) {
                return;
            }
            $this->correctionResume->supersede($locked, $receipt);
            $locked->update([
                'direction_relay_comment_id' => null,
                'direction_answer_key' => null,
                'direction_answer_source_turn_id' => null,
                'review_handled_comment_id' => $receipt->id,
                'communication_failures' => 0,
            ]);
        });
    }

    /** Finishes a reviewing direction hold from the resolution comment already stored. */
    private function replayHeldDirectionReview(Task $task): void
    {
        $opened = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->latest('id')
            ->value('opened_comment_id');
        $resolution = TaskComment::query()
            ->where('task_id', $task->id)
            ->where('type', TaskCommentType::Resolution)
            ->when(is_int($opened), fn ($query) => $query->where('id', '>', $opened))
            ->latest('id')
            ->first();
        if (! $resolution instanceof TaskComment) {
            return;
        }
        app(StoreTaskCommentAction::class)->commitHeldDirectionReview($task, $resolution);
    }

    /**
     * @param  array<string, int>  $handled
     */
    private function requestAssistance(Task $task, Task $group, string $reason, ?TaskSessionObservation $observation = null, AssistanceKind $kind = AssistanceKind::Failure, ?string $question = null, array $handled = [], ?TaskComment $source = null): void
    {
        $notifyReason = null;
        DB::transaction(function () use ($task, $group, $reason, $kind, $question, $handled, $source, &$notifyReason): void {
            if ($handled !== []) {
                $task->update($handled);
            }
            $wasAsking = (bool) DB::table('tasks')->where('id', $group->id)->value('assistance_requested');
            TaskAssistance::apply($task, $kind, $question, $reason);
            $task->refresh();
            $taskKind = $task->assistance_kind;
            $taskReason = $task->assistance_reason;
            if ($task->assistance_requested && $taskKind instanceof AssistanceKind && is_string($taskReason)) {
                TaskAssistance::apply($group, $taskKind, $task->assistance_question, $taskReason);
            } else {
                TaskAssistance::apply($group, $kind, $question, $reason);
            }
            $group->refresh();
            if ($kind === AssistanceKind::Direction && $source instanceof TaskComment) {
                if (TaskQuestions::escalateOpen($task, $source)) {
                    $task->update(['consult_comment_id' => null]);
                } else {
                    TaskQuestions::recordBlocked($task, $source);
                }
            }
            $groupReason = $group->assistance_reason;
            if (! $wasAsking && $group->assistance_requested && is_string($groupReason)) {
                $notifyReason = $groupReason;
            }
        });
        if (is_string($notifyReason)) {
            $this->coder->assistance($group, $notifyReason);
        }
    }

    /**
     * Marks a pending Pi resume accepted or superseded from the turn now on the acting thread (ADR 0167).
     * An accepted key is not sent again. A different turn does not reuse it either.
     */
    private function reconcilePiRestart(Task $task, TaskThreadObservation $acting): void
    {
        if ($task->pi_restart_reservation !== self::PiRestartPending || (int) $task->pi_restart_thread_id !== $acting->threadId) {
            return;
        }
        $turnId = $acting->turnId;
        if (! is_string($turnId) || $turnId === '') {
            return;
        }
        if ($turnId === $task->pi_restart_key) {
            $task->update(['pi_restart_reservation' => self::PiRestartAccepted]);

            return;
        }
        if ($turnId !== $task->pi_restart_source_turn_id) {
            $task->update(['pi_restart_reservation' => self::PiRestartSuperseded]);
        }
    }

    /**
     * Resumes a Pi turn that failed only because the server restarted (ADR 0167).
     *
     * @return 'handled'|'assist'|'skip' handled owns the tick, assist asks for assistance, skip keeps today's failure path
     */
    private function resumePiServerRestart(Task $task, Task $group, TaskThreadObservation $acting): string
    {
        $record = AgentThread::query()->find($acting->threadId);
        if (! $record instanceof AgentThread || ! $this->isServerRestartError($record->driver, $acting->error)) {
            return 'skip';
        }
        if (! is_string($acting->turnId) || $acting->turnId === '') {
            return 'skip';
        }
        if ($task->pi_restart_reservation === self::PiRestartPending
            && (int) $task->pi_restart_thread_id === $acting->threadId
            && $acting->turnId === $task->pi_restart_source_turn_id
            && is_string($task->pi_restart_key)
            && $task->pi_restart_key !== '') {
            $this->sendPiRestartResume($task, $group, $acting, $task->pi_restart_key);

            return 'handled';
        }
        if ((int) $task->pi_restart_resumes >= self::PiServerRestartResumeLimit) {
            return 'assist';
        }
        $key = (string) Str::uuid();
        $task->update([
            'pi_restart_resumes' => (int) $task->pi_restart_resumes + 1,
            'pi_restart_key' => $key,
            'pi_restart_thread_id' => $acting->threadId,
            'pi_restart_source_turn_id' => $acting->turnId,
            'pi_restart_reservation' => self::PiRestartPending,
        ]);
        $this->sendPiRestartResume($task, $group, $acting, $key);

        return 'handled';
    }

    /** Only Pi restart failures can resume a task-agent turn. */
    private function isServerRestartError(string $driver, ?string $error): bool
    {
        return $driver === 'pi' && $error === self::PiServerRestartError;
    }

    private function sendPiRestartResume(Task $task, Task $group, TaskThreadObservation $acting, string $key): void
    {
        $this->turnFetcher->beforeTurn($group);
        try {
            $this->actor->resumeInterruptedTurn($group, $acting, self::PiServerRestartContinue, $key);
        } catch (AgentDriverException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $this->clearCommunicationFailures($task);
    }

    private function classifyAvailable(Task $group, TaskSessionObservation $observation): TaskSessionDecision
    {
        $this->clearUnavailable($group);

        return new TaskSessionDecision(TaskSessionNextAction::Noop, 1.0, 'Waiting for a known agent state.');
    }

    private function clearUnavailable(Task $group): void
    {
        Task::topLevel()->whereKey($group->id)->whereNotNull('agent_unavailable_since')->update([
            'agent_unavailable_since' => null, 'agent_unavailable_notified_at' => null,
        ]);
    }

    private function unavailableDecision(Task $group): TaskSessionDecision
    {
        Task::topLevel()->whereKey($group->id)->whereNull('agent_unavailable_since')->update(['agent_unavailable_since' => now()]);
        $group->refresh();
        $grace = max(0, Config::integer('orbit.tasks.observation_grace_seconds', 120));
        if ($group->agent_unavailable_since !== null && $group->agent_unavailable_since->lte(now()->subSeconds($grace))) {
            $claimed = Task::topLevel()->whereKey($group->id)
                ->where('agent_unavailable_since', $group->agent_unavailable_since)
                ->whereNull('agent_unavailable_notified_at')
                ->update(['agent_unavailable_notified_at' => now()]);
            if ($claimed === 1) {
                return TaskSessionDecision::escalate('Agent observation unavailable beyond the grace period.');
            }
        }

        return new TaskSessionDecision(TaskSessionNextAction::Noop, 1.0, 'Waiting for an available agent observation.');
    }

    private function advance(Task $group, Task $task, TaskSessionDecision $decision, TaskSessionObservation $observation): void
    {
        $group = $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
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

        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            $this->settle($group);
        }
    }

    /**
     * Claims the oldest todo group that fits, provisions its Instance, and starts its first task.
     *
     * @param  list<int>  $skipped  Groups whose provisioning or start failed. A caller that passes the same list to later
     *                              calls tries each failing group at most once.
     */
    public function claimNext(array &$skipped = []): ?Task
    {
        $resumed = $this->resumeWaitingSandbox($skipped);
        if ($resumed instanceof Task) {
            return $resumed;
        }
        while (true) {
            $reserved = DB::transaction(function () use ($skipped): ?Task {
                $candidates = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
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

                    $group->task_compute ??= $group->project->task_compute;
                    $group->capacity_wait_reason = null;
                    $group->status = TaskGroupStatus::Reserved;
                    $group->reserved_at = now();
                    $group->save();

                    return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
                }

                return null;
            });

            if (! $reserved instanceof Task) {
                return null;
            }

            try {
                $instance = $this->provisioning->provision(InstanceProvisionIntent::for($reserved));
            } catch (TaskCapacityException $exception) {
                $this->releaseReservation($reserved, $exception->getMessage());
                $this->removeEndedWorkspace($reserved, null);

                if ($exception->fleetFull) {
                    return null;
                }

                $skipped[] = $reserved->id;

                continue;
            } catch (Throwable $exception) {
                report($exception);
                $instance = InstanceProvisionFailure::fromException($exception);
            }

            if (! $instance instanceof Instance) {
                $this->releaseProvisioningFailure($reserved, $instance);
                $this->removeEndedWorkspace($reserved, null);
                $skipped[] = $reserved->id;

                continue;
            }

            try {
                $started = DB::transaction(fn (): ?Task => $this->startReserved($reserved, $instance));
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

        if (! $started instanceof Task) {
            $this->removeEndedWorkspace($reserved, $instance);

            return null;
        }

        $this->startFirstTask($started);

        return $started->fresh(['tasks', 'project', 'taskable']) ?? $started;
    }

    /** @param list<int> $skipped */
    private function resumeWaitingSandbox(array &$skipped): ?Task
    {
        $groups = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
            ->where('task_compute', TaskCompute::Vm)->where('status', TaskGroupStatus::WaitingForReview)
            ->whereNull('watched_pr_completion')
            ->whereHas('tasks', fn ($tasks) => $tasks->where('status', TaskStatus::Todo))
            ->when($skipped !== [], fn ($query) => $query->whereNotIn('id', $skipped))
            ->with(['project', 'tasks', 'taskable'])->orderBy('id')->get();
        foreach ($groups as $group) {
            if (self::resumeBlocked($group)) {
                continue;
            }
            $this->resumeWaitingSubtask($group);
            $fresh = $group->fresh(['project', 'tasks', 'taskable']);
            if ($fresh instanceof Task && $fresh->status === TaskGroupStatus::Running) {
                return $fresh;
            }
            $skipped[] = $group->id;
        }

        return null;
    }

    /**
     * Attaches the provisioned Instance and moves the group from reserved to running. The group keeps the Instance
     * whenever it cannot start, so a later claim reuses it and cancellation removes it.
     *
     * A group that is no longer the reservation this claim made, because the tick returned it to todo or cancellation
     * ended it, keeps its status. It gains the Instance only when it holds none.
     */
    private function startReserved(Task $reserved, Instance $instance): ?Task
    {
        $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
            ->with(['tasks', 'project', 'taskable'])
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
        if ($group->assistance_kind !== AssistanceKind::Direction && self::isClaimFailureReason($group->assistance_reason)) {
            $group->fill(TaskAssistance::cleared());
        }
        $group->save();

        $previousKey = $this->provisioningFailureKey($group);
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => self::WorkspaceStartedActivity,
            'subject_type' => $group::class, 'subject_id' => $group->id,
            'properties' => ['instance_id' => $instance->id],
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:tick', 'status' => 'completed',
        ]);
        DB::afterCommit(fn (): bool => $this->rememberBackoff($previousKey, null, 'workspace provisioning'));

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    /**
     * Returns a group whose start failed to todo with a fixed reason and keeps its Instance. When this write fails
     * too, the group stays reserved until the tick returns it to todo.
     */
    private function releaseFailedStart(Task $reserved, Instance $instance): void
    {
        try {
            DB::transaction(function () use ($reserved, $instance): void {
                $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
                if (! $group instanceof Task) {
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

    private function provisioningFailureKey(Task $group): string
    {
        // The committed activity is a durable reset witness. Cache cleanup is only garbage collection.
        $generation = Activity::query()->where('log_name', 'tasks')
            ->where('subject_type', $group::class)->where('subject_id', $group->id)
            ->where('description', self::WorkspaceStartedActivity)->max('id');

        return 'tasks:provisioning-failures:'.$group->id.(is_numeric($generation) ? ':'.$generation : '');
    }

    /** Counts a failed claim and requests assistance only while its reservation is still current. */
    private function releaseProvisioningFailure(Task $reserved, ?InstanceProvisionFailure $failure): void
    {
        DB::transaction(function () use ($reserved, $failure): void {
            $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
            if (! $group instanceof Task || ! $this->holdsReservation($group, $reserved)) {
                return;
            }

            $key = $this->provisioningFailureKey($group);
            $failures = ($this->readBackoff($key, 'workspace provisioning')['failures'] ?? 0) + 1;
            if (! $this->rememberBackoff($key, ['failures' => $failures, 'due' => 0], 'workspace provisioning')) {
                $failures = 1;
            }
            $reason = self::ProvisioningFailedReason.($failure === null ? '' : ' '.$failure->cause);
            $group->status = TaskGroupStatus::Todo;
            if (! $group->assistance_requested) {
                $group->assistance_reason = $reason;
            }
            $group->save();
            $threshold = config('orbit.tasks.provisioning_failure_threshold', 3);
            if ($failures >= max(1, is_numeric($threshold) ? (int) $threshold : 3)) {
                $wasAsking = $group->assistance_requested;
                $applied = TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);
                if (! $wasAsking && $applied) {
                    DB::afterCommit(fn () => $this->coder->assistance($group, $reason));
                }
            }
        });
    }

    /**
     * Returns a group to todo only while this claim still holds its reservation, so a claim never overwrites a
     * group that the tick released, a cancel ended, or a newer claim reserved.
     */
    private function releaseReservation(Task $reserved, string $reason): void
    {
        DB::transaction(function () use ($reserved, $reason): void {
            $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
            if (! $group instanceof Task || ! $this->holdsReservation($group, $reserved)) {
                return;
            }

            $group->capacity_wait_reason = $reason;
            $group->status = TaskGroupStatus::Todo;
            if ($group->assistance_kind !== AssistanceKind::Direction && self::isClaimFailureReason($group->assistance_reason)) {
                $group->fill(TaskAssistance::cleared());
            }
            $group->save();
        });
    }

    /**
     * A group cancelled or completed while its claim ran holds no workspace, because the cancel left the
     * workspace to the claim. The claim removes the Instance it provisioned, or the group's unattached
     * `task-{group id}` workspace when provisioning failed part way.
     */
    private function removeEndedWorkspace(Task $reserved, ?Instance $instance): void
    {
        $group = Task::topLevel()->with('taskable')->find($reserved->id);
        if (! $group instanceof Task || ! self::hasEnded($group) || $group->taskable_id !== null) {
            return;
        }

        try {
            $leftover = $instance instanceof Instance ? Instance::query()->find($instance->id) : $this->workspaces->find($group);
            if ($leftover instanceof Instance) {
                $this->workspaces->remove($leftover, $group);
            } else {
                $this->workspaces->execute($group);
            }
        } catch (Throwable $exception) {
            // The workspace stays findable by name, so a repeated cancel removes it.
            report($exception);
            $this->workspaces->recordFailure($group, $exception);
        }
    }

    private static function hasEnded(Task $group): bool
    {
        return in_array($group->status, [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed], true);
    }

    private function holdsReservation(Task $group, Task $reserved): bool
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

        foreach ($stale(Task::topLevel())->orderBy('id')->pluck('id') as $id) {
            $updated = $stale(Task::topLevel()->whereKey($id))->update([
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

    /** The first retry delay. The later delays are 2, 5, 10, and 30 minutes, and further failures stay at 30. */
    public const int AbandonedWorkspaceBackoffSeconds = 60;

    /**
     * Delay before the next push or removal attempt after this many failures.
     * The first failure waits one minute, then 2, 5, 10, and 30 minutes.
     */
    public static function retryDelaySeconds(int $failures): int
    {
        $schedule = [self::AbandonedWorkspaceBackoffSeconds, 120, 300, 600, 1800];
        $index = min(max($failures, 1), count($schedule)) - 1;

        return $schedule[$index];
    }

    /**
     * Removes the workspace of a cancelled or completed group, attached or found by its `task-{group id}` name and
     * branch, and of a settling group whose merged pull request cleanup failed. An unattached workspace waits while
     * a live claim can still own it. The tick does not remove the workspace of a group that is still active.
     *
     * One query selects the candidates. A failed removal is reported, asks for assistance, and backs off per
     * Instance, so a workspace that keeps failing never blocks the others. The sweep stops starting removals once
     * it has spent its time budget; the rest wait for the next tick. Success clears removal assistance only.
     * A cancelled group pushes its stored approval before the checkout is deleted. A user Instance attached to a
     * non-managed group is never a candidate.
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
            $backoff = $this->readBackoff($backoffKey, 'workspace removal');
            if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
                continue;
            }

            $instance = Instance::query()->find($workspace->id);
            $group = Task::topLevel()->find($workspace->getAttribute('ended_task_group_id'));
            if (! $instance instanceof Instance || ! $group instanceof Task || ! $this->shouldRemoveWorkspace($group, $instance)) {
                continue;
            }

            try {
                $this->pushCancelledApproval($group, $instance);
                $this->workspaces->remove($instance, $group);
                $this->rememberBackoff($backoffKey, null, 'workspace removal');
                $this->releaseRemovedWorkspace($group, $instance->id);
                Log::warning('Removed the workspace of an ended task group.', ['task_group_id' => $group->id, 'instance_id' => $instance->id]);
                $removed++;
            } catch (Throwable $exception) {
                report($exception);
                $prefix = in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)
                    ? RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix
                    : RemoveTaskWorkspaceAction::RemovalFailedPrefix;
                $this->workspaces->recordFailure($group, $exception, $prefix);
                $this->extendBackoff($backoffKey, $backoff, 'workspace removal');
            }
        }

        return $removed + $this->removeAbandonedSandboxes($started);
    }

    /** Recover recorded reservations whose claim ended before it attached a workspace. */
    private function removeAbandonedSandboxes(CarbonInterface $started): int
    {
        $removed = 0;
        $candidates = TaskSandbox::query()->where('state', '!=', SandboxState::Destroyed)
            ->whereNotIn('id', Instance::query()->whereNotNull('task_sandbox_id')->select('task_sandbox_id'))
            ->where(fn ($query) => $query->where('desired_power', 'destroyed')->orWhere(fn ($warm) => $warm->whereNull('group_id')->where('warm_pool', false))->orWhereHas('group', fn ($groups) => $groups
                ->where('execution_mode', TaskExecutionMode::Managed)->where('task_compute', TaskCompute::Vm)
                ->where(fn ($claim) => $claim->whereNull('reserved_at')->orWhere('reserved_at', '<=', RemoveTaskWorkspaceAction::reservationCutoff()))
                ->whereIn('status', [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled])))
            ->orderBy('created_at')->get();
        foreach ($candidates as $sandbox) {
            if ($started->diffInSeconds(now(), true) >= self::AbandonedWorkspaceBudgetSeconds) {
                break;
            }
            $key = 'tasks.sandbox-removal.'.$sandbox->id;
            $backoff = $this->readBackoff($key, 'sandbox removal');
            if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
                continue;
            }
            try {
                app(RemoveTaskSandboxAction::class)->unattached($sandbox, endedOnly: true);
                $this->rememberBackoff($key, null, 'sandbox removal');
                if ($sandbox->group instanceof Task) {
                    $this->workspaces->clearFailure($sandbox->group);
                }
                $removed++;
            } catch (Throwable $exception) {
                report($exception);
                if ($sandbox->group instanceof Task) {
                    $this->workspaces->recordFailure($sandbox->group, $exception);
                }
                $this->extendBackoff($key, $backoff, 'sandbox removal');
            }
        }

        return $removed;
    }

    /** Retries merged pull request cleanup at once the first time, then on the same per-Instance backoff as the sweep. */
    private function completeMergedGroup(Task $group): void
    {
        $attached = $group->taskable;
        $instance = $attached instanceof Instance ? $attached : $this->workspaces->find($group);
        $backoffKey = $instance instanceof Instance ? 'tasks.workspace-removal.'.$instance->id : null;
        $backoff = is_string($backoffKey) ? $this->readBackoff($backoffKey, 'workspace removal') : null;

        if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
            return;
        }

        try {
            if (TaskPullRequestHealth::isReason($group->assistance_reason)) {
                $group->update(TaskAssistance::cleared());
            }
            $this->completeGroup->execute($group, finishWhenRemovalFails: false);
            if (is_string($backoffKey)) {
                $this->rememberBackoff($backoffKey, null, 'workspace removal');
            }
        } catch (Throwable $exception) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.$exception->getMessage(), replaceFailure: true);
            if (is_string($backoffKey)) {
                $this->extendBackoff($backoffKey, $backoff, 'workspace removal');
            }
        }
    }

    /** Pushes the latest stored approval before a cancelled checkout is deleted. A group with no approval is unchanged. */
    private function pushCancelledApproval(Task $group, Instance $instance): void
    {
        if ($group->status !== TaskGroupStatus::Cancelled) {
            return;
        }
        $commit = TaskFinalReview::cancelPushCommit($group);
        if ($commit === null) {
            return;
        }
        $group->setRelation('taskable', $instance);
        $group->loadMissing('project');
        $this->publisher->push($group, $commit);
    }

    private function shouldRemoveWorkspace(Task $group, Instance $instance): bool
    {
        if ($instance->project_id !== $group->project_id || $this->attachedToUnmanagedGroup($instance)) {
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

        return in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)
            && is_string($group->assistance_reason)
            && str_starts_with($group->assistance_reason, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix);
    }

    private function releaseRemovedWorkspace(Task $group, int $instanceId): void
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
    private function extendBackoff(string $key, ?array $backoff, string $label): void
    {
        $failures = ($backoff['failures'] ?? 0) + 1;
        $delay = self::retryDelaySeconds($failures);
        $this->rememberBackoff($key, ['failures' => $failures, 'due' => now()->addSeconds($delay)->getTimestamp()], $label, $delay * 2);
    }

    /** The next attempt waits until `due`. A missing backoff, or a cache read that fails, means try now. */
    private function retryIsDue(string $key, string $label): bool
    {
        $backoff = $this->readBackoff($key, $label);

        return $backoff === null || $backoff['due'] <= now()->getTimestamp();
    }

    /**
     * Reads a retry backoff. A cache error is logged and read as no backoff, so one bad read never stops the
     * sweep or the tick.
     *
     * @return array{failures: int, due: int}|null
     */
    private function readBackoff(string $key, string $label): ?array
    {
        try {
            $backoff = Cache::get($key);
        } catch (Throwable $exception) {
            Log::warning('The '.$label.' backoff could not be read.', ['key' => $key, 'exception' => $exception::class, 'reason' => $exception->getMessage()]);

            return null;
        }

        return is_array($backoff) && is_int($backoff['failures'] ?? null) && is_int($backoff['due'] ?? null)
            ? ['failures' => $backoff['failures'], 'due' => $backoff['due']]
            : null;
    }

    /**
     * Stores or clears a retry backoff. A cache error is logged and the attempt continues.
     *
     * @param  array{failures: int, due: int}|null  $backoff
     */
    private function rememberBackoff(string $key, ?array $backoff, string $label, int $seconds = 0): bool
    {
        try {
            if ($backoff === null) {
                $written = Cache::forget($key);
                if (! $written && ! Cache::has($key)) {
                    return true;
                }
            } elseif ($seconds === 0) {
                $written = Cache::forever($key, $backoff);
            } else {
                $written = Cache::put($key, $backoff, now()->addSeconds($seconds));
            }

            if (! $written) {
                Log::warning('The '.$label.' backoff could not be written.', ['key' => $key, 'reason' => 'Cache store returned false.']);
            }

            return $written;
        } catch (Throwable $exception) {
            Log::warning('The '.$label.' backoff could not be written.', ['key' => $key, 'exception' => $exception::class, 'reason' => $exception->getMessage()]);

            return false;
        }
    }

    /** A user Instance that backs a non-managed group is never a task workspace the sweep may delete. */
    private function attachedToUnmanagedGroup(Instance $instance): bool
    {
        return Task::topLevel()
            ->where('taskable_type', TaskableType::Instance)
            ->where('taskable_id', $instance->id)
            ->where('execution_mode', '!=', TaskExecutionMode::Managed->value)
            ->exists();
    }

    /** @return Collection<int, Instance> */
    private function abandonedWorkspaces(): Collection
    {
        $workspaceName = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT('task-', tasks.id)",
            default => "'task-' || tasks.id",
        };

        $cutoff = RemoveTaskWorkspaceAction::reservationCutoff();
        $mergePrefix = RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.'%';

        return Instance::query()
            ->select('instances.*', 'tasks.id as ended_task_group_id')
            ->join('tasks', function ($join) use ($workspaceName): void {
                $join->on('tasks.project_id', '=', 'instances.project_id')
                    ->whereNull('tasks.parent_id')
                    ->where(function ($link) use ($workspaceName): void {
                        $link->whereColumn('tasks.taskable_id', 'instances.id')
                            ->orWhere(function ($named) use ($workspaceName): void {
                                $named->whereRaw("instances.name = {$workspaceName}")
                                    ->whereColumn('instances.branch_override', 'instances.name')
                                    ->whereNull('tasks.taskable_id');
                            });
                    });
            })
            ->where('tasks.execution_mode', TaskExecutionMode::Managed->value)
            ->whereNotExists(function ($userGroup): void {
                $userGroup->selectRaw('1')
                    ->from('tasks as user_groups')
                    ->whereNull('user_groups.parent_id')
                    ->whereColumn('user_groups.taskable_id', 'instances.id')
                    ->where('user_groups.taskable_type', TaskableType::Instance)
                    ->where('user_groups.execution_mode', '!=', TaskExecutionMode::Managed->value);
            })
            ->where(function ($ended) use ($cutoff, $mergePrefix): void {
                $ended->where(function ($finished) use ($cutoff): void {
                    $finished->whereIn('tasks.status', [TaskGroupStatus::Cancelled->value, TaskGroupStatus::Completed->value])
                        ->where(function ($reservation) use ($cutoff): void {
                            $reservation->whereColumn('tasks.taskable_id', 'instances.id')
                                ->orWhereNull('tasks.reserved_at')
                                ->orWhere('tasks.reserved_at', '<=', $cutoff);
                        });
                })->orWhere(function ($settling) use ($mergePrefix): void {
                    $settling->whereIn('tasks.status', TaskGroupStatus::awaitingCompletion())
                        ->where('tasks.assistance_reason', 'like', $mergePrefix);
                });
            })
            ->orderBy('instances.id')
            ->get();
    }

    public static function isClaimFailureReason(?string $reason): bool
    {
        return in_array($reason, self::ClaimFailureReasons, true)
            || (is_string($reason) && str_starts_with($reason, self::ProvisioningFailedReason.' '));
    }

    /** Claims todo groups until none fits. A failing group is tried once. */
    public function claimAvailable(): int
    {
        $skipped = [];
        $started = 0;

        while ($this->claimNext($skipped) instanceof Task) {
            $started++;
        }

        $this->warmPool->reconcile();

        return $started;
    }

    /**
     * ADR 0133: a reviewer turn is read-only. The receipt stays unapplied while the workspace differs
     * from the pair recorded with the review request, unless a newer implementer turn explains the
     * difference or the commit below is Orbit's. One reminder, then the scheduler waits for a newer
     * stopped reviewer turn before it looks again. That later turn applies the outcome when the
     * workspace matches, and asks for assistance when it still differs. Another poll of the reminded
     * turn does neither. A failed read is a communication failure, not a change. A review notified
     * before a baseline existed has nothing to compare, so its outcome applies as before.
     * A commit already stored on the receipt is Orbit's. Publication retries only while HEAD is
     * that commit and the working tree still matches. The HEAD from before the approval is not
     * accepted after Orbit has committed, so a reset that drops the commit is refused.
     *
     * @return 'apply'|'orbit_commit'|'wait'|'reopened'|'reminded'|'unreadable'
     */
    private function reviewWorkspaceDecision(Task $group, Task $task, TaskThreadObservation $reviewer, TaskComment $receipt, TaskSessionObservation $observation): string
    {
        try {
            $current = $this->workspaceSnapshot($group);
        } catch (TaskCheckException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return 'unreadable';
        }
        if ($this->workspaceMatchesReview($task, $receipt, $current)) {
            return 'apply';
        }
        if (! $task->isFinalReview() && $this->receiptOutcome($receipt) === TaskTurnOutcome::Approved && ! $this->committedApproval($receipt) && $this->recoveredCommit($task, $current) !== null) {
            return 'orbit_commit';
        }
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($this->newerImplementerTurn($task, $implementer)) {
            if ($implementer instanceof TaskThreadObservation && $this->newerTurnHasStopped($task->completion_handoff_turn_id, $implementer)) {
                $this->reopenHandoff($group, $task, $receipt);

                return 'reopened';
            }

            return 'wait';
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

        return 'reminded';
    }

    private function workspaceMatchesReview(Task $task, TaskComment $receipt, TaskWorkspaceSnapshot $current): bool
    {
        $head = $task->review_workspace_head;
        $tree = $task->review_workspace_tree;
        $storedCommit = $receipt->commit_sha;
        $orbitCommit = is_string($storedCommit) && $storedCommit !== '' ? $storedCommit : null;
        $treeMatches = ! is_string($tree) || $tree === '' || $current->tree === $tree;
        $headMatches = $orbitCommit !== null
            ? $current->head === $orbitCommit
            : ! is_string($head) || $head === '' || $current->head === $head;

        return $headMatches && $treeMatches;
    }

    /**
     * Orbit's commit after a lost response: HEAD's parent is the HEAD recorded with the review
     * request, and HEAD's tree is the tree recorded with that request (ADR 0133).
     */
    private function recoveredCommit(Task $task, TaskWorkspaceSnapshot $current): ?string
    {
        $head = $task->review_workspace_head;
        $tree = $task->review_workspace_tree;
        if (! is_string($head) || $head === '' || ! is_string($tree) || $tree === '' || $current->head === '' || $current->head === $head) {
            return null;
        }
        if ($current->parent !== $head || $current->commitTree !== $tree) {
            return null;
        }

        return $current->head;
    }

    private function newerImplementerTurn(Task $task, ?TaskThreadObservation $implementer): bool
    {
        $handoff = $task->completion_handoff_turn_id;

        return is_string($handoff) && $handoff !== ''
            && $implementer instanceof TaskThreadObservation
            && is_string($implementer->turnId) && $implementer->turnId !== ''
            && $implementer->turnId !== $handoff;
    }

    /**
     * A newer implementer turn changed the workspace during review. The reviewer outcome is not
     * applied and the reviewer is not reminded. The subtask needs a new receipt and a passing check.
     */
    private function reopenHandoff(Task $group, Task $task, TaskComment $receipt): void
    {
        $task->update([
            'status' => TaskStatus::Running,
            'review_handled_comment_id' => $receipt->id,
            'review_attempt' => $task->review_attempt + 1,
            'review_reminder_attempt' => null,
            'review_reminder_input_id' => null,
            'completion_attempt' => $task->completion_attempt + 1,
            'completion_handoff_attempt' => null,
            'completion_handoff_comment_id' => null,
            'completion_reminder_attempt' => null,
            'completion_reminder_input_id' => null,
            'communication_failures' => 0,
        ]);
        $group->update(['status' => TaskGroupStatus::Running]);
    }

    /** @throws TaskCheckException */
    private function workspaceSnapshot(Task $group): TaskWorkspaceSnapshot
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }

        return $this->checks->snapshot($instance);
    }

    /**
     * Starts a fresh reviewer for this subtask's first review, or continues that subtask's reviewer.
     * A working continued reviewer gets no request. An earlier subtask's reviewer does not
     * delay a fresh review. The task stays unnotified until the request is sent.
     */
    private function nudgeReviewer(Task $task, ?TaskThreadObservation $reviewer): void
    {
        TaskExecutionHold::run($task->parent, fn () => $this->nudgeAdmittedReviewer($task, $reviewer));
    }

    private function nudgeAdmittedReviewer(Task $task, ?TaskThreadObservation $reviewer): void
    {
        if ($task->review_notified_attempt === $task->review_attempt) {
            return;
        }
        $existing = $this->subtaskReviewer($task);
        if ($existing !== null && $reviewer !== null && $reviewer->threadId === $existing->id && $this->isWorking($reviewer)) {
            return;
        }
        $group = $task->parent()->with('taskable')->firstOrFail();

        try {
            // Read at send time. Do not copy the hash from an earlier check row: the request may have waited.
            $snapshot = $this->workspaceSnapshot($group);
            if ($existing === null && $this->spawner instanceof TaskAgentSpawner) {
                $reserved = $this->spawner->reserveReviewer($task);
                if (! $this->prepareTurn($group, $task, TaskThreadRole::Reviewer, $reserved)) {
                    return;
                }
                $threadId = $this->spawner->spawnReviewer($task);
                if ($threadId === null) {
                    throw new AgentDriverException('The reviewer conversation could not be started.');
                }
            } elseif ($existing === null) {
                if (! $this->prepareTurn($group, $task, TaskThreadRole::Reviewer)) {
                    return;
                }
                $threadId = $this->spawner->spawnReviewer($task);
                if ($threadId === null) {
                    throw new AgentDriverException('The reviewer conversation could not be started.');
                }
            } else {
                if (! $this->prepareTurn($group, $task, TaskThreadRole::Reviewer, $existing->id)) {
                    return;
                }
                $this->spawner->requestReview($task);
                $continued = $this->subtaskReviewer($task);
                $threadId = $continued instanceof AgentThread ? $continued->id : $existing->id;
            }
            $group->update(['reviewer_agent_thread_id' => $threadId]);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, self::ReviewRequestFailedReason.' ('.class_basename($exception).').');

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

    /** The reviewer thread for this subtask, preferring the one the group currently points at. */
    private function subtaskReviewer(Task $task): ?AgentThread
    {
        $query = AgentThread::query()
            ->where('task_group_id', $task->parent_id)
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%');
        $pointed = Task::topLevel()->whereKey($task->parent_id)->value('reviewer_agent_thread_id');
        if (is_numeric($pointed)) {
            $match = (clone $query)->whereKey((int) $pointed)->first();
            if ($match instanceof AgentThread) {
                return $match;
            }
        }

        return $query->orderByDesc('id')->first();
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

    public function settleImplementer(Task $task, ?TaskThreadObservation $reviewer = null): Task
    {
        $task->parent->requireManagedExecution();
        $group = DB::transaction(function () use ($task): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->with(['tasks', 'project', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);

            if (TaskExecutionHold::active($group) || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)
                || $group->status !== TaskGroupStatus::Running || $locked->status !== TaskStatus::Running) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }

            $locked->status = TaskStatus::Reviewing;
            $locked->save();
            $group->status = TaskGroupStatus::Reviewing;
            $group->save();

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        $reviewing = $group->tasks->first(
            static fn (Task $candidate): bool => $candidate->id === $task->id,
        );

        if ($reviewing instanceof Task && $reviewing->status === TaskStatus::Reviewing) {
            $this->nudgeReviewer($reviewing, $reviewer);
        }

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    public function startTask(Task $task): Task
    {
        $task->load('parent');
        $task->parent->requireManagedExecution();
        if (TaskExecutionHold::active($task->parent) || RequestEndedPullRequestAssistanceAction::isReason($task->parent->assistance_reason)) {
            return $task->parent->fresh(['tasks', 'project', 'taskable']) ?? $task->parent;
        }
        $started = $this->activateRunningTask($task);
        $this->beginRunningTask($started);

        $group = $started->parent;

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    /**
     * Cancels a todo subtask without starting anything, or cancels a running subtask and starts the next
     * one. `$stop` makes the remote calls that stop a running subtask's implementer and check. It runs
     * outside any database transaction, so a slow Node or agent never holds the Gateway's SQLite write
     * lock. An exception from `$stop` leaves the subtask and its check running. The state change then
     * applies only when the subtask is still running: when it moved on while `$stop` ran, its new state
     * stands and the cancel returns a conflict.
     *
     * @param  Closure(Task): void  $stop
     */
    public function cancelRunningSubtask(Task $parent, Task $task, Closure $stop): Task
    {
        $parent->requireManagedExecution();
        $candidate = Task::query()->where('parent_id', $parent->id)->findOrFail($task->id);

        if ($candidate->status === TaskStatus::Todo) {
            $group = DB::transaction(function () use ($parent, $task): Task {
                $locked = Task::query()->where('parent_id', $parent->id)->lockForUpdate()->findOrFail($task->id);
                $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                    ->lockForUpdate()
                    ->findOrFail($locked->parent_id);

                if ($locked->status !== TaskStatus::Todo || ! in_array($group->status, [
                    TaskGroupStatus::Todo,
                    TaskGroupStatus::Running,
                    TaskGroupStatus::Reviewing,
                    TaskGroupStatus::Settling,
                    TaskGroupStatus::WaitingForReview,
                ], true)) {
                    throw new ResourceOperationException(
                        errorCode: 'tasks.subtask_not_running',
                        message: __('Only a todo or running subtask can be cancelled.'),
                        status: 409,
                    );
                }

                if (TaskExecutionHold::active($group)) {
                    return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
                }
                $assistanceReason = $this->markSubtaskCancelled($locked);
                $tasks = $this->lockedTasks($group);
                $this->clearCancelledSubtaskAssistance($group, $locked, $assistanceReason);
                if (! $this->hasOpenSubtask($tasks)) {
                    $group->status = TaskGroupStatus::Settling;
                }
                $group->save();

                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            });

            if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
                return $this->settle($group, requestMissingPullRequest: false, checkReturningPullRequest: false);
            }

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        if ($candidate->status !== TaskStatus::Running) {
            throw new ResourceOperationException(
                errorCode: 'tasks.subtask_not_running',
                message: __('Only a todo or running subtask can be cancelled.'),
                status: 409,
            );
        }

        $stop($candidate);

        $next = null;
        $group = DB::transaction(function () use ($parent, $task, &$next): Task {
            $locked = Task::query()->where('parent_id', $parent->id)->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);

            if ($locked->status !== TaskStatus::Running) {
                throw new ResourceOperationException(
                    errorCode: 'tasks.subtask_not_running',
                    message: __('The subtask stopped running while Orbit stopped it, so its new state stands.'),
                    status: 409,
                );
            }

            if (TaskExecutionHold::active($group)) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }
            $assistanceReason = $this->markSubtaskCancelled($locked);
            $tasks = $this->lockedTasks($group);
            $this->clearCancelledSubtaskAssistance($group, $locked, $assistanceReason);
            if (RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }

            $next = $this->lowestTodo($tasks);
            if ($next instanceof Task) {
                try {
                    $this->markRunning($next, $tasks);
                    $group->status = TaskGroupStatus::Running;
                } catch (TaskSequenceException) {
                    $next = null;
                    $group->status = $this->statusWithOpenSubtasks($group, $tasks);
                }
            } elseif ($this->hasOpenSubtask($tasks)) {
                $group->status = $this->statusWithOpenSubtasks($group, $tasks);
            } else {
                $group->status = TaskGroupStatus::Settling;
            }
            $group->save();

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        if ($next instanceof Task && $next->status === TaskStatus::Running) {
            $this->beginRunningTask($next);
        }

        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return $this->settle($group);
        }

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    public function acceptReview(Task $task): Task
    {
        $task->parent->requireManagedExecution();
        $next = null;
        $group = DB::transaction(function () use ($task, &$next): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->with(['tasks', 'project', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);

            if (TaskExecutionHold::active($group) || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)
                || $group->status !== TaskGroupStatus::Reviewing || $locked->status !== TaskStatus::Reviewing) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
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

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        if ($next instanceof Task && $next->status === TaskStatus::Running) {
            $this->recordSubtaskStart($next);
            $this->assignImplementer($next);
        }

        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return $this->settle($group);
        }

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    public function settle(
        Task $group,
        bool $requestMissingPullRequest = true,
        bool $checkReturningPullRequest = true,
    ): Task {
        $group->requireManagedExecution();
        $group->loadMissing(['project', 'tasks', 'taskable']);

        if (! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        $url = $group->pr_url;

        if (TaskFinalReview::due($group, $group->tasks)) {
            $this->beginFinalReview($group);

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        if (! is_string($url) || $url === '') {
            if ($requestMissingPullRequest && ! $this->lowestTodo($group->tasks) instanceof Task) {
                $this->requestMissingPullRequest($group);
            }

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        // ADR 0164: returning to settling refreshes metrics and does not post task_group.settled again.
        $returning = $group->settled_at !== null;
        if ($returning && $checkReturningPullRequest) {
            $this->checkReturningPullRequest($group);
        }
        $metrics = $this->metrics->collect($group);
        $group->tokens = $metrics->tokens;
        $group->line_diff = $metrics->lineDiff;
        $group->duration_ms = $metrics->durationMs;
        $group->questions = $metrics->questions;
        $group->escalations = $metrics->escalations;
        $group->settled_at ??= now();
        if ($group->task_compute === TaskCompute::Vm && $group->status === TaskGroupStatus::Settling) {
            $group->status = TaskGroupStatus::WaitingForReview;
        }
        $group->save();

        $settled = $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        $this->sandboxes->review($settled);

        if ($settled->notify_coder && ! $returning) {
            $this->coder->notify($settled);
        }

        return $settled->fresh(['tasks', 'project', 'taskable']) ?? $settled;
    }

    /**
     * Appends one fixup when a current problem still has one left, or asks for assistance when every
     * current problem is at the cap. A waiting subtask starts instead, and no fixup is appended.
     *
     * ADR 0164 bounds the fixups. No fixup is appended while the head is the one the last fixup was
     * created for, or while a check on the head has been pending for 60 minutes or less. A completed
     * genuine failure is still reported during that wait. A conflict does not wait. A check pending for
     * more than 60 minutes is infrastructure, with cancelled checks and checks that could not start.
     * A fixup that changed nothing asks for assistance instead of a second try on the same result.
     * A group keeps at most three Gateway fixups in total.
     */
    private function healOpenPullRequest(Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $reviews): void
    {
        if ($this->hasBusyTask($group->tasks)) {
            return;
        }

        // An operator subtask resumes past a review-and-merge request that waits for one (ADR 0203).
        if ($this->lowestTodo($group->tasks) instanceof Task
            && (! $this->otherAssistance($group) || TaskFinalReview::isResumableReason($group->assistance_reason))) {
            $this->resumeWaitingSubtask($group);

            return;
        }

        if ($this->otherAssistance($group)) {
            return;
        }

        // ADR 0203: unreviewed work gets its final review first. No settling fixup builds on it meanwhile.
        if ($group->reviewsBeforePush() && TaskFinalReview::hasUnreviewedWork($group)) {
            $this->beginFinalReview($group);

            return;
        }

        // ADR 0203: GitHub's merge commit would land without Orbit's review, so a review-and-merge task never
        // asks GitHub to update its branch. A conflict gets a merge fixup instead, and a behind branch still merges.
        if (($health->conflicts || $health->behind) && ! $group->reviewsBeforePush()) {
            $update = is_string($health->headSha) && $health->headSha !== ''
                ? $this->pullRequestUpdater->updateBranch($group, $health->headSha)
                : TaskBranchUpdate::Unavailable;
            if ($update !== TaskBranchUpdate::Conflict) {
                $this->reportPullRequestHealth($group, new TaskPullRequestHealth('open', $update === TaskBranchUpdate::Accepted ? [] : [
                    'GitHub could not update the base branch. Orbit will retry after a fresh observation.',
                ]));

                return;
            }
            if (! $health->conflicts) {
                // A concurrent base change can reveal a conflict after the health read.
                return;
            }
        }

        if ($health->infrastructureChecks === []) {
            $this->rememberBackoff($this->infrastructureBackoffKey($group), null, 'infrastructure check');
        }

        if ($health->problems === []) {
            $this->reportPullRequestHealth($group, $health);
        }

        $fixups = $this->orderedTasks($group->tasks)
            ->filter(static fn (Task $task): bool => $task->isSettlingFixup())
            ->values();
        $latest = $fixups->last();
        if ($latest instanceof Task && is_string($latest->fixup_head_sha) && $latest->fixup_head_sha === $health->headSha) {
            if ($this->fixupChangedNothing($latest)) {
                $reason = 'Fixup subtask #'.$latest->id.' changed nothing, so Orbit does not try again on the same result.';
                if ($health->problems === [] && ($reviews?->selection->requests ?? []) !== []) {
                    $this->reviewFeedbackAssistance($group, $reason, 'unchanged-head');
                } else {
                    $this->reportPullRequestHealth($group, $health, $reason);
                }
            }

            return;
        }

        $this->changeReviewFeedback($group, 'unchanged-head', null);

        // A check pending for 60 minutes or less is not a result yet. Report a completed genuine
        // failure, and append no check fixup. A conflict does not wait. Infrastructure backoff starts
        // only once every current problem is infrastructure and nothing is still inside that span.
        if ($health->checksYoungPending && ! $health->conflicts) {
            if ($health->failedChecks !== []) {
                $this->reportPullRequestHealth($group, $health);
            }

            return;
        }

        if (! $health->conflicts && $health->failedChecks === [] && $health->infrastructureChecks !== []) {
            $this->awaitInfrastructureChecks($group, $health);

            return;
        }

        $plan = $this->nextFixup($group, $health, $health->checksYoungPending);
        if (! $plan instanceof TaskSettlingFixup && ! $health->checksPending && $health->infrastructureChecks === []) {
            if ($this->appendReviewFeedback($group, $health, $reviews)) {
                $this->resumeWaitingSubtask($group);

                return;
            }
        }
        if (! $plan instanceof TaskSettlingFixup) {
            $this->reportPullRequestHealth(
                $group,
                $health,
                $this->reachedFixupIdentityCaps($group, $health, $health->checksYoungPending),
            );

            return;
        }

        if (array_sum($this->fixupCountsSinceOperatorWork($group)) >= TaskSettlingFixup::GroupLimit) {
            $this->reportPullRequestHealth($group, $health, 'Orbit already appended '.TaskSettlingFixup::GroupLimit.' fixups to this group.');

            return;
        }

        if ($this->appendFixup($group, $this->mergeBaseFirstWhenGreenAhead($group, $health, $plan), $health->headSha) instanceof Task) {
            $this->resumeWaitingSubtask($group);
        }
    }

    /**
     * A failed check may already be fixed on the base. When the base tip passed the merge check, or
     * `Required checks` without one, and is strictly ahead of the head's merge base, the fixup merges the base first.
     * The GitHub reads run before appendFixup takes its lock.
     */
    private function mergeBaseFirstWhenGreenAhead(Task $group, TaskPullRequestHealth $health, TaskSettlingFixup $plan): TaskSettlingFixup
    {
        if (! str_starts_with($plan->identity, 'check:') || ! is_string($health->baseRef) || $health->baseRef === ''
            || ! is_string($health->headSha) || $health->headSha === '') {
            return $plan;
        }
        $check = $group->project->mergeCheckName() ?? TaskPullRequestCheck::ROLLUP_NAMES[0];

        return $this->merger->baseTipGreenAhead($group, $health->baseRef, $health->headSha, $check)
            ? $plan->mergingBaseFirst($health->baseRef)
            : $plan;
    }

    private static function isReviewFeedbackReason(?string $reason): bool
    {
        return TaskGitHubReviewFeedback::isReason($reason);
    }

    /** Source-specific causes are durable; presentation never replaces unrelated assistance. */
    private function changeReviewFeedback(Task $group, string $source, ?string $reason): void
    {
        if ($this->reviewFeedback->change($group, [$source => $reason])) {
            $this->broadcasts->groupChanged($group->id);
            if ($group->assistance_requested && is_string($group->assistance_reason)) {
                $this->coder->assistance($group, $group->assistance_reason);
            }
        }
    }

    private function reviewFeedbackAssistance(Task $group, string $reason, string $source): void
    {
        $this->changeReviewFeedback($group, $source, $reason);
    }

    private function reportMissingReviewFixups(Task $group): void
    {
        $missing = $this->reviewConsumption->missingFixups($group);
        foreach ($this->reviewFeedback->causes($group) as $source => $reason) {
            if (str_starts_with($source, 'missing:') && ! isset($missing[$source])) {
                $this->changeReviewFeedback($group, $source, null);
            }
        }
        foreach ($missing as $source => $reason) {
            $this->reviewFeedbackAssistance($group, $reason, $source);
        }
    }

    private function reviewReadBackoffKey(Task $group, string $operation): string
    {
        return 'tasks.github-review-read.'.$group->id.($operation === 'list' ? '' : '.'.$operation);
    }

    private function reviewReadResult(Task $group, TaskReviewReadStatus $status, string $operation = 'list'): void
    {
        $key = $this->reviewReadBackoffKey($group, $operation);
        if ($status === TaskReviewReadStatus::Unreadable) {
            $backoff = $this->readBackoff($key, 'GitHub review read');
            if ($backoff !== null && $backoff['failures'] >= self::InfrastructureCheckRetries) {
                $this->reviewFeedbackAssistance($group, 'The trusted review source remains unreadable ('.$operation.'). Restore review-read access.', $operation);
            }
            $this->extendBackoff($key, $backoff, 'GitHub review read');

            return;
        }
        if ($status === TaskReviewReadStatus::Changed) {
            return;
        }
        if ($status === TaskReviewReadStatus::Overflow) {
            $this->reviewFeedbackAssistance($group, 'The complete GitHub review source exceeds the pagination limit ('.$operation.'). Split the findings or append a scoped operator subtask.', $operation);

            return;
        }
        $this->rememberBackoff($key, null, 'GitHub review read');
        if ($status === TaskReviewReadStatus::InvalidTrust) {
            $this->reviewFeedbackAssistance($group, 'The operator reviewer configuration is invalid. Correct the trusted numeric accounts.', 'trust');

            return;
        }
        $this->changeReviewFeedback($group, $operation, null);
        if ($operation === 'list') {
            $this->changeReviewFeedback($group, 'trust', null);
        }
    }

    private function observeReviewFeedback(Task $group): ?TaskReviewObservation
    {
        if (! $this->retryIsDue('tasks.github-review-read.'.$group->id, 'GitHub review read')) {
            return null;
        }
        $observation = $this->reviewWatcher->reviews($group, fresh: true);
        $this->reviewReadResult($group, $observation->status);
        if (in_array($observation->status, [TaskReviewReadStatus::Complete, TaskReviewReadStatus::Disabled], true)) {
            $eligible = array_map(static fn ($review): int => $review->id, $observation->selection->requests ?? []);
            if ($eligible === []) {
                $this->changeReviewFeedback($group, 'unchanged-head', null);
            }
            foreach ($this->reviewFeedback->causes($group) as $source => $reason) {
                if (preg_match('/^review:([0-9]+):/', $source, $match) === 1 && ! in_array((int) $match[1], $eligible, true)) {
                    $this->rememberBackoff($this->reviewReadBackoffKey($group, $source), null, 'GitHub review read');
                    $this->changeReviewFeedback($group, $source, null);
                }
            }
        }

        return $observation;
    }

    /** Final source reads stay outside the append transaction. Local eligibility is checked again inside it. */
    private function appendReviewFeedback(Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $observation): bool
    {
        if ($observation?->status !== TaskReviewReadStatus::Complete || $observation->repository === null || $observation->number === null) {
            return false;
        }
        $dispatchGroup = clone $group;
        $counts = $this->fixupCountsSinceOperatorWork($group);
        foreach ($observation->selection->requests ?? [] as $review) {
            if ($this->reviewConsumption->consumed($group, $observation->repository->owner.'/'.$observation->repository->name, $observation->number, $review->id)) {
                continue;
            }
            if (($counts['review:'.$review->reviewerId] ?? 0) >= TaskSettlingFixup::Limit || array_sum($counts) >= TaskSettlingFixup::GroupLimit) {
                $this->reviewFeedbackAssistance($group, 'The automatic fixup cap was reached for account '.$review->reviewerId.' or this group. Append a scoped operator subtask.', 'review:'.$review->id.':cap');

                continue;
            }
            $this->changeReviewFeedback($group, 'review:'.$review->id.':cap', null);
            $findings = 'review:'.$review->id.':findings';
            $validation = 'review:'.$review->id.':validation';
            if (! $this->retryIsDue($this->reviewReadBackoffKey($group, $findings), 'GitHub review findings')
                || ! $this->retryIsDue($this->reviewReadBackoffKey($group, $validation), 'GitHub review revalidation')) {
                continue;
            }
            $result = $this->reviewWatcher->reviewCandidate($group, $review->id, fresh: true);
            $this->reviewReadResult($group, $result->status, $findings);
            if ($result->status !== TaskReviewReadStatus::Complete || $result->candidate === null) {
                return false;
            }
            $candidate = $result->candidate;
            if ($candidate->head !== $health->headSha) {
                return false;
            }
            try {
                $packet = TaskReviewFindingsPacket::fromCandidate($candidate);
            } catch (\InvalidArgumentException|\LengthException $exception) {
                $this->reviewFeedbackAssistance($group, $exception->getMessage(), 'review:'.$review->id.':packet');

                return false;
            }
            $this->changeReviewFeedback($group, 'review:'.$review->id.':packet', null);
            $result = $this->reviewWatcher->revalidateReviewCandidate($group, $candidate);
            $this->reviewReadResult($group, $result->status, $validation);
            if ($result->status !== TaskReviewReadStatus::Complete || $result->candidate === null) {
                return false;
            }

            return $this->appendFixup($dispatchGroup, TaskSettlingFixup::reviewPlan($dispatchGroup->project->taskCheckCommand(), $packet), $candidate->head, $candidate) instanceof Task;
        }

        return false;
    }

    /**
     * Whether a fixup left the pull request head where it was: it never reached an approval, or its
     * approved commit is the head it was created for. A fixup that did commit waits for GitHub to see the push.
     */
    private function fixupChangedNothing(Task $fixup): bool
    {
        $commit = TaskComment::query()
            ->where('task_id', $fixup->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');

        return ! is_string($commit) || $commit === '' || $commit === $fixup->fixup_head_sha;
    }

    /**
     * Only cancelled checks, checks that could not start, or checks pending for more than 60 minutes
     * stand between the pull request and a merge. Those are not the branch's fault, so no fixup is
     * appended. The group re-evaluates on the backoff of 1, 2, 5, 10, and 30 minutes, and asks for
     * assistance when they persist after that.
     */
    private function awaitInfrastructureChecks(Task $group, TaskPullRequestHealth $health): void
    {
        $key = $this->infrastructureBackoffKey($group);
        $backoff = $this->readBackoff($key, 'infrastructure check');
        if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
            return;
        }
        if ($backoff !== null && $backoff['failures'] >= self::InfrastructureCheckRetries) {
            $this->reportPullRequestHealth($group, $health, 'Those checks were cancelled or could not start, and did not recover. Re-run them.');

            return;
        }
        $this->extendBackoff($key, $backoff, 'infrastructure check');
    }

    private function infrastructureBackoffKey(Task $group): string
    {
        return 'tasks.pull-request-infrastructure.'.$group->id;
    }

    /** Whether assistance was requested for a cause other than the open pull request's own problems. */
    private function otherAssistance(Task $group): bool
    {
        return $group->assistance_requested && ! TaskPullRequestHealth::isReason($group->assistance_reason)
            && ! self::isReviewFeedbackReason($group->assistance_reason);
    }

    /** Review-source problems are observations, not a hold on otherwise authorized ongoing work. */
    private function progressBlockedByAssistance(Task $group): bool
    {
        return $group->assistance_requested && ! self::isReviewFeedbackReason($group->assistance_reason);
    }

    /**
     * A baseline that failed on a red default-branch commit retries once the branch tip is green and contains
     * that commit, as an operator resolution would. GitHub is read before the lock, at most every five minutes
     * per check. The queue then locks the rows and validates the retry again. The recovery after it resets.
     */
    private function queueBaselineRetryOnGreenTip(Task $group, Task $task): bool
    {
        $check = $this->retryBaseline->unrequested($task);
        if (! $check instanceof TaskCheck || ! Cache::add('tasks.baseline-green-tip.'.$check->id, true, 300)) {
            return false;
        }
        $name = $group->project->mergeCheckName() ?? TaskPullRequestCheck::ROLLUP_NAMES[0];
        if ($this->merger->requiredCheck($group, $check->head_before, $name) !== RequiredCheckState::Failed) {
            return false;
        }
        $tip = $this->merger->greenDefaultTipAfter($group, $check->head_before, $name);
        if ($tip === null) {
            return false;
        }

        return TaskExecutionHold::run($group, fn (): bool => DB::transaction(function () use ($task, $check, $name, $tip): bool {
            if ($this->retryBaseline->unrequested($task)?->id !== $check->id) {
                return false;
            }
            $comment = TaskComment::query()->create([
                'task_group_id' => $task->parent_id, 'task_id' => $task->id, 'completion_attempt' => $task->completion_attempt,
                'type' => TaskCommentType::Resolution, 'author' => 'orbit', 'posted_at' => now(),
                'body' => 'The baseline failed on '.$check->head_before.', where '.$name.' failed. The default branch tip '.$tip
                    .' contains it and passed '.$name.', so Orbit retries the baseline.',
            ]);

            return $this->retryBaseline->queue($task, $comment);
        })) === true;
    }

    /** @param  Collection<int, Task>  $tasks */
    private function hasBusyTask(Collection $tasks): bool
    {
        return $tasks->contains(
            static fn (Task $task): bool => in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true),
        );
    }

    private function nextFixup(Task $group, TaskPullRequestHealth $health, bool $conflictOnly = false): ?TaskSettlingFixup
    {
        $counts = $this->fixupCountsSinceOperatorWork($group);

        foreach (TaskSettlingFixup::plans($group->project->taskCheckCommand(), $health->conflicts, $health->baseRef, $health->failedChecks) as $plan) {
            if ($conflictOnly && $plan->conflictBase() === null) {
                continue;
            }
            if (($counts[$plan->identity] ?? 0) < TaskSettlingFixup::Limit) {
                return $plan;
            }
        }

        return null;
    }

    /** Explain each current problem whose per-identity cap has been reached in the active window. */
    private function reachedFixupIdentityCaps(Task $group, TaskPullRequestHealth $health, bool $conflictOnly): ?string
    {
        $counts = $this->fixupCountsSinceOperatorWork($group);
        $reasons = [];

        foreach (TaskSettlingFixup::plans($group->project->taskCheckCommand(), $health->conflicts, $health->baseRef, $health->failedChecks) as $plan) {
            if ($conflictOnly && $plan->conflictBase() === null) {
                continue;
            }
            $count = $counts[$plan->identity] ?? 0;
            if ($count >= TaskSettlingFixup::Limit) {
                $reasons[] = 'Orbit reached the cap of '.TaskSettlingFixup::Limit.' fixups for '.$plan->identity.' in the current window ('.$count.' counted).';
            }
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    /** @return array<string, int> */
    private function fixupCountsSinceOperatorWork(Task $group): array
    {
        $counts = $this->reviewConsumption->orphanCharges($group, $group->tasks);
        foreach ($this->fixupsSinceOperatorWork($this->orderedTasks($group->tasks)) as $task) {
            if ($task->isSettlingFixup()) {
                $counts[(string) $task->fixup_problem] = ($counts[(string) $task->fixup_problem] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Fixup caps include only entries positioned after the latest completed non-fixup task.
     * Task order is defined by position (with id as a tie-breaker), so position defines this reset boundary.
     * Every fixup status counts; unfinished non-fixup tasks do not reset the window.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    private function fixupsSinceOperatorWork(Collection $tasks): Collection
    {
        $lastCompletedOperatorPosition = $tasks
            ->filter(static fn (Task $task): bool => $task->isOperatorWork() && $task->status === TaskStatus::Completed)
            ->max('position');

        return $tasks
            ->filter(static fn (Task $task): bool => is_string($task->fixup_problem)
                && $task->fixup_problem !== ''
                && ($lastCompletedOperatorPosition === null || $task->position > $lastCompletedOperatorPosition))
            ->values();
    }

    private function appendFixup(Task $group, TaskSettlingFixup $plan, ?string $headSha, ?TaskReviewCandidate $candidate = null): ?Task
    {
        return DB::transaction(function () use ($group, $plan, $headSha, $candidate): ?Task {
            $locked = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->lockForUpdate()
                ->findOrFail($group->id);
            if (! in_array($locked->status, TaskGroupStatus::awaitingCompletion(), true) || $this->otherAssistance($locked)) {
                return null;
            }

            $tasks = $this->lockedTasks($locked);
            if ($this->hasBusyTask($tasks) || $this->lowestTodo($tasks) instanceof Task) {
                return null;
            }
            $locked->setRelation('tasks', $tasks);
            if ($candidate !== null) {
                $trust = TaskReviewTrust::fromConfig($candidate->repository, config('orbit.tasks.github_reviewers', []));
                if ($locked->pr_url !== $group->pr_url || $locked->project_id !== $group->project_id
                    || ! is_string($locked->pr_url) || $candidate->repository->pullRequestNumber($locked->pr_url) !== $candidate->number
                    || ! $trust->valid || $trust->revision !== $candidate->trustRevision
                    || $this->reviewConsumption->consumed($locked, $candidate->repository->owner.'/'.$candidate->repository->name, $candidate->number, $candidate->review->id)) {
                    return null;
                }
            }
            $latest = $this->orderedTasks($tasks)->filter(static fn (Task $task): bool => $task->isSettlingFixup())->last();
            if ($latest instanceof Task && $latest->fixup_head_sha === $headSha) {
                return null;
            }
            $counts = $this->fixupCountsSinceOperatorWork($locked);
            $count = $counts[$plan->identity] ?? 0;
            $total = array_sum($counts);
            if ($count >= TaskSettlingFixup::Limit || $total >= TaskSettlingFixup::GroupLimit) {
                return null;
            }

            $fixup = Task::query()->create([
                'parent_id' => $locked->id,
                'position' => StoredInteger::fromOrZero($tasks->max('position')) + 1,
                'title' => $plan->title,
                'brief' => $plan->brief,
                'deliverables' => $plan->deliverables,
                'fixup_problem' => $plan->identity,
                'fixup_head_sha' => $headSha,
                'status' => TaskStatus::Todo,
            ]);
            if ($candidate !== null) {
                $this->reviewConsumption->record($locked, $fixup, $candidate, $tasks);
            }

            return $fixup;
        });
    }

    /**
     * Starts a later subtask left todo after the group returned to running, including a conflict fixup
     * whose base fetch failed. The group's first subtask is started by claim, not by this retry.
     */
    private function resumeStrandedSubtask(Task $group): void
    {
        if ($this->progressBlockedByAssistance($group) || $this->hasBusyTask($group->tasks)) {
            return;
        }

        $todo = $this->lowestTodo($group->tasks);
        if (! $todo instanceof Task || ! $this->followedAnotherSubtask($todo, $group->tasks)) {
            return;
        }

        $this->resumeWaitingSubtask($group);
    }

    /**
     * Returns a settling group to running and starts its lowest todo subtask. A check fixup or an operator
     * subtask becomes running in that same commit; the implementer starts afterwards. A conflict fixup
     * returns the group to running before the base fetch, and a failed fetch leaves that subtask todo.
     */
    private function resumeWaitingSubtask(Task $group): void
    {
        TaskExecutionHold::run($group, fn () => $this->resumeAdmittedSubtask($group));
    }

    private function resumeAdmittedSubtask(Task $group): void
    {
        if ($group->relationLoaded('tasks') && $this->hasBusyTask($group->tasks)) {
            return;
        }

        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        if (! in_array($group->status, [TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview, TaskGroupStatus::Running], true)) {
            return;
        }
        if ($group->status === TaskGroupStatus::Running && $this->progressBlockedByAssistance($group)) {
            return;
        }
        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && self::resumeBlocked($group)) {
            return;
        }
        if ($this->hasBusyTask($group->tasks)) {
            return;
        }

        $todo = $this->lowestTodo($group->tasks);
        if (! $todo instanceof Task) {
            return;
        }

        if (! $this->sandboxes->resume($group)) {
            return;
        }

        $base = $this->conflictBase($todo);
        if ($base !== null && in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            $this->leaveSettling($group);
        }
        if (! $this->prepareResumedWorkspace($group, $todo)) {
            return;
        }

        $started = $this->activateResumedTask($todo);
        if ($started instanceof Task) {
            $this->beginRunningTask($started, alreadyFetched: true);
        }
    }

    /**
     * Reuses the general turn fetch, then fast-forwards the workspace to `origin/task-{group id}` when
     * it is strictly behind. A failure waits out #760's backoff of 1, 2,
     * 5, 10, and 30 minutes, leaves the subtask todo, and asks for assistance on the fifth failure.
     * On a group with no pull request, a missing `origin/task-{group id}` is not a failure.
     */
    private function prepareResumedWorkspace(Task $group, Task $todo): bool
    {
        $key = 'tasks.resume-fetch.'.$todo->id;
        if (! $this->retryIsDue($key, 'resume fetch')) {
            return false;
        }
        try {
            $fresh = $group->fresh(['project', 'taskable']) ?? $group;
            $this->turnFetcher->fetch($fresh);
            $this->bases->fastForward($fresh, ! is_string($fresh->pr_url) || $fresh->pr_url === '');
        } catch (Throwable $exception) {
            $this->extendBackoff($key, $this->readBackoff($key, 'resume fetch'), 'resume fetch');
            $this->recordCommunicationFailure($todo, $group, $exception->getMessage());

            return false;
        }
        $this->rememberBackoff($key, null, 'resume fetch');
        $this->clearCommunicationFailures($todo);

        return true;
    }

    /** Records the return to running before a conflict fixup's base fetch, which stays outside the commit. */
    private function leaveSettling(Task $group): void
    {
        $this->clearResumeAssistance($group);
        $group->status = TaskGroupStatus::Running;
        $group->save();
    }

    /**
     * Marks the resumed subtask running, and returns a settling group to running, in one transaction.
     * The caller starts the implementer after the commit.
     */
    private function activateResumedTask(Task $todo): ?Task
    {
        try {
            return DB::transaction(function () use ($todo): ?Task {
                $locked = Task::query()->lockForUpdate()->findOrFail($todo->id);
                $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                    ->lockForUpdate()
                    ->findOrFail($locked->parent_id);
                if (TaskExecutionHold::active($group) || ! in_array($group->status, [TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview, TaskGroupStatus::Running], true)) {
                    return null;
                }
                if ($group->status === TaskGroupStatus::Running && $this->progressBlockedByAssistance($group)) {
                    return null;
                }
                if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && self::resumeBlocked($group)) {
                    return null;
                }

                $tasks = $this->lockedTasks($group);
                if ($this->hasBusyTask($tasks)) {
                    return null;
                }
                if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
                    $this->clearResumeAssistance($group);
                    $group->status = TaskGroupStatus::Running;
                    $group->save();
                }

                $this->markRunning($locked, $tasks);

                return $locked->fresh(['parent.tasks', 'parent.project', 'parent.taskable']) ?? $locked;
            });
        } catch (TaskSequenceException) {
            return null;
        }
    }

    /** @param  Collection<int, Task>  $tasks */
    private function followedAnotherSubtask(Task $task, Collection $tasks): bool
    {
        return $this->orderedTasks($tasks)->contains(
            static fn (Task $candidate): bool => $candidate->position < $task->position
                || ($candidate->position === $task->position && $candidate->id < $task->id),
        );
    }

    /** The base ref stored on a conflict fixup, or null when the subtask is not one. */
    private function conflictBase(Task $task): ?string
    {
        $problem = $task->fixup_problem;
        if (! is_string($problem) || ! str_starts_with($problem, 'conflict:')) {
            return null;
        }

        return substr($problem, strlen('conflict:'));
    }

    private function requestMissingPullRequest(Task $group): void
    {
        $reason = self::MissingPullRequestPrefix.' Cancel the group to push its approved commits to task-'.$group->id.' and remove its workspace.';
        if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason)) {
            $this->coder->assistance($group, $reason);
        }
    }

    public static function isMissingPullRequestReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::MissingPullRequestPrefix);
    }

    /** Another assistance cause blocks a resume. The missing-pull-request reason does not. */
    public static function resumeBlocked(Task $group): bool
    {
        return $group->assistance_requested
            && ! TaskPullRequestHealth::isReason($group->assistance_reason)
            && ! self::isMissingPullRequestReason($group->assistance_reason)
            && ! self::isReviewFeedbackReason($group->assistance_reason)
            && ! TaskFinalReview::isResumableReason($group->assistance_reason);
    }

    /** Clears the pull-request and missing-pull-request reasons when a resumed subtask starts. */
    private function clearResumeAssistance(Task $group): void
    {
        if (! TaskPullRequestHealth::isReason($group->assistance_reason) && ! self::isMissingPullRequestReason($group->assistance_reason)
            && ! TaskFinalReview::isResumableReason($group->assistance_reason)) {
            return;
        }
        $group->fill(TaskAssistance::cleared());
    }

    /**
     * Asks for assistance once per distinct set of pull request problems, and withdraws only its own
     * request when the pull request is healthy again. Another cause of assistance is left alone.
     */
    private function reportPullRequestHealth(Task $group, TaskPullRequestHealth $health, ?string $extra = null): void
    {
        $group->refresh();
        $ownRequest = TaskPullRequestHealth::isReason($group->assistance_reason);

        if ($health->problems === []) {
            if ($ownRequest) {
                $group->update(TaskAssistance::cleared());
            }

            return;
        }

        $reason = $health->reason($extra);
        if ($group->assistance_requested && (! $ownRequest || $group->assistance_reason === $reason)) {
            return;
        }

        if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: $ownRequest)) {
            $this->coder->assistance($group, $reason);
        }
    }

    private function startFirstTask(Task $group): void
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
            $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);
            $tasks = $this->lockedTasks($group);

            if (! TaskExecutionHold::active($group)) {
                $this->markRunning($locked, $tasks);
            }

            return $locked->fresh(['parent.tasks', 'parent.project', 'parent.taskable']) ?? $locked;
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
            throw TaskSequenceException::siblingRunning($task->requireGroupId(), $running->id);
        }

        $next = $this->lowestTodo($tasks);

        if (! $next instanceof Task || $next->id !== $task->id || ! $this->predecessorsCompleted($task, $tasks)) {
            throw TaskSequenceException::notNext($task->id, $task->requireGroupId());
        }

        $task->status = TaskStatus::Running;
        $task->started_at ??= now();
        $task->save();
    }

    /**
     * Starts a subtask the way startTask does: records the start commit, then runs the baseline check
     * when no implementer has started in the group yet, or starts the implementer.
     */
    private function beginRunningTask(Task $task, bool $alreadyFetched = false): void
    {
        TaskExecutionHold::run($task->parent, fn () => $this->beginAdmittedTask($task, $alreadyFetched));
    }

    private function beginAdmittedTask(Task $task, bool $alreadyFetched = false): void
    {
        $group = $task->parent()->with(['project', 'taskable'])->firstOrFail();
        if ($task->isFinalReview()) {
            // An incoming pull request has no implementer before its first final review, so that review runs the
            // baseline on the fresh workspace first. Setup then serves the fixups that follow.
            if ($group->reviewsIncomingPullRequest() && ($this->baselineCheck($task)?->status === TaskCheckStatus::Running || $this->needsBaseline($task))) {
                if ($this->admitTopology($group, $task)) {
                    $this->handleBaseline($group, $task);
                }

                return;
            }
            $this->startFinalReview($group, $task, $alreadyFetched);

            return;
        }
        if ($this->baselineCheck($task)?->status === TaskCheckStatus::Running) {
            $this->handleBaseline($group, $task);

            return;
        }
        if (! $this->admitTopology($group, $task)) {
            return;
        }
        $this->recordSubtaskStart($task);
        if ($this->needsBaseline($task)) {
            $group = $task->parent()->with(['project', 'taskable'])->firstOrFail();
            $this->handleBaseline($group, $task);
        } else {
            $this->assignImplementer($task, $alreadyFetched);
        }
    }

    /**
     * A group checks its fresh workspace once, before any implementer has started in it.
     */
    private function needsBaseline(Task $task): bool
    {
        $sandboxId = $task->parent->taskable instanceof Instance ? $task->parent->taskable->task_sandbox_id : null;
        if ($sandboxId !== null) {
            return ! TaskCheck::query()->where('task_sandbox_id', $sandboxId)->where('kind', TaskCheckKind::Baseline->value)
                ->where('status', TaskCheckStatus::Passed->value)->whereHas('task', fn ($query) => $query->where('parent_id', $task->parent_id))->exists();
        }
        $started = Task::query()->where('parent_id', $task->parent_id)->whereNotNull('implementer_agent_thread_id')->exists()
            || AgentThread::query()->where('task_group_id', $task->parent_id)->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')->exists();

        // ADR 0203: an incoming pull request's first final review ran the baseline without an implementer.
        $scope = $task->parent->reviewsIncomingPullRequest()
            ? TaskCheck::query()->whereHas('task', fn ($query) => $query->where('parent_id', $task->parent_id))
            : TaskCheck::query()->where('task_id', $task->id);

        return ! $started && ! $scope->where('kind', TaskCheckKind::Baseline->value)
            ->where('status', TaskCheckStatus::Passed->value)->exists();
    }

    private function hasImplementer(Task $task): bool
    {
        return $task->implementer_agent_thread_id !== null
            || AgentThread::query()->where('task_id', $task->id)->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')->exists();
    }

    /**
     * The implementer's first turn has started once its conversation exists.
     * A reserved row has not started, so a failed start read can still be retried.
     */
    private function implementerTurnStarted(Task $task): bool
    {
        $threadId = $task->implementer_agent_thread_id;
        if ($threadId !== null) {
            $externalId = AgentThread::query()->whereKey($threadId)->value('external_id');
            if (is_string($externalId) && ! str_starts_with($externalId, TaskAgentSpawner::PendingPrefix)) {
                return true;
            }
        }

        return AgentThread::query()
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Implementer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')
            ->exists();
    }

    /**
     * Runs the Project's setup steps and check on the fresh workspace. The first implementer starts only
     * after it passes, so an agent never starts on a broken checkout.
     */
    private function handleBaseline(Task $group, Task $task): void
    {
        TaskExecutionHold::run($group, fn () => $this->handleAdmittedBaseline($group, $task));
    }

    private function handleAdmittedBaseline(Task $group, Task $task): void
    {
        $check = $this->baselineCheck($task);
        $instance = $group->taskable;
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && ($check->pid === self::BASELINE_UNSTARTED_PID || $check->process_started === '')) {
            if ($check->started_at->lt(now()->subSeconds(self::BASELINE_START_LIMIT_SECONDS))) {
                $this->requestAssistance($task, $group, 'The baseline start was interrupted, and a check may still run in the workspace.');
            }

            return;
        }
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && $instance instanceof Instance) {
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
            if ($task->isFinalReview()) {
                $this->startFinalReview($group, $task, false);

                return;
            }
            $this->assignImplementer($task);

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->where('status', $check->status->value)->count()
            : 0;
        $branch = 'task-'.$group->id;
        $reason = match (true) {
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step !== null && $check->failed_step !== self::CHECK_ERROR_STEP => "The Project setup step \"{$check->failed_step}\" failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Fix the setup or the branch, then post a resolution on this subtask to retry the baseline. The task's check shows the output.",
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed => "The Project baseline check failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Fix the configured check or the branch, then post a resolution on this subtask to retry the baseline. The task's check shows the output.",
            $status === TaskCheckStatus::Cancelled => 'An operator cancelled the baseline check before any agent started.',
            $check instanceof TaskCheck && $status === TaskCheckStatus::Changed && $repeats >= 2 => 'The workspace changed while the baseline check ran, twice. Changed paths: '.implode(', ', $check->changed_paths ?? []).'.',
            $status === TaskCheckStatus::Lost && $repeats >= 2 => 'The baseline check stopped twice without a result.',
            default => null,
        };
        if ($reason !== null) {
            $this->requestAssistance($task, $group, $reason);

            return;
        }

        if ($check instanceof TaskCheck && ! $this->admitTopology($group, $task)) {
            return;
        }
        $this->startBaseline($group, $task);
    }

    private function startBaseline(Task $group, Task $task): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }
        $setup = array_values(ProjectLifecycleStep::query()
            ->where('project_id', $group->project_id)
            ->where('phase', LifecyclePhase::Setup->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (ProjectLifecycleStep $step): array => ['name' => $step->name, 'command' => $step->command, 'timeout_seconds' => $step->timeout_seconds])
            ->all());
        $command = $instance->project->taskCheckCommand();
        $claim = $this->claimBaseline($task);
        if (! $claim instanceof TaskCheck) {
            return;
        }
        try {
            $process = $this->checks->start($instance, $command, $setup);
        } catch (TaskCheckException $exception) {
            $this->releaseBaselineClaim($claim);
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $stored = TaskCheck::query()->whereKey($claim->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->where('pid', self::BASELINE_UNSTARTED_PID)
            ->update([
                'pid' => $process->pid,
                'process_started' => $process->started,
                'head_before' => $process->head,
                'tree_before' => $process->tree,
                'updated_at' => now(),
            ]);
        if ($stored !== 1) {
            try {
                $this->checks->cancel($instance, $process);
            } catch (TaskCheckException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());
            }

            return;
        }
        $this->broadcasts->groupChanged($group->id);
        $this->clearCommunicationFailures($task);
    }

    /**
     * Reserves the one running baseline for this subtask before its process starts.
     * The subtask row lock decides, so the scheduler tick and the Todo move cannot both start one.
     */
    private function claimBaseline(Task $task): ?TaskCheck
    {
        return DB::transaction(function () use ($task): ?TaskCheck {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()->lockForUpdate()->findOrFail($locked->parent_id);
            if (TaskExecutionHold::active($group) || $locked->status !== TaskStatus::Running) {
                return null;
            }
            $sandboxId = $group->taskable instanceof Instance ? $group->taskable->task_sandbox_id : null;
            $running = TaskCheck::query()->where('task_sandbox_id', $sandboxId)
                ->where('task_id', $locked->id)
                ->where('kind', TaskCheckKind::Baseline->value)
                ->where('status', TaskCheckStatus::Running->value)
                ->lockForUpdate()
                ->exists();
            if ($running) {
                return null;
            }

            return TaskCheck::query()->create([
                'task_id' => $locked->id,
                'task_sandbox_id' => $sandboxId,
                'kind' => TaskCheckKind::Baseline,
                'task_comment_id' => $locked->resolution_delivered_comment_id,
                'status' => TaskCheckStatus::Running,
                'pid' => self::BASELINE_UNSTARTED_PID,
                'process_started' => '',
                'head_before' => '',
                'tree_before' => '',
                'started_at' => now(),
            ]);
        });
    }

    private function releaseBaselineClaim(TaskCheck $claim): void
    {
        TaskCheck::query()->whereKey($claim->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->where('pid', self::BASELINE_UNSTARTED_PID)
            ->delete();
    }

    /**
     * The baseline that decides this subtask. A running claim wins, even before its process
     * starts. A newer row is a duplicate and is not a verdict.
     */
    private function baselineCheck(Task $task): ?TaskCheck
    {
        $sandboxId = $task->parent->taskable instanceof Instance ? $task->parent->taskable->task_sandbox_id : null;
        $running = TaskCheck::query()->where('task_sandbox_id', $sandboxId)
            ->where('task_id', $task->id)
            ->where('kind', TaskCheckKind::Baseline->value)
            ->where('status', TaskCheckStatus::Running->value)
            ->orderBy('id')
            ->first();
        if ($running instanceof TaskCheck) {
            return $running;
        }

        return TaskCheck::query()->where('task_sandbox_id', $sandboxId)
            ->where('task_id', $task->id)
            ->where('kind', TaskCheckKind::Baseline->value)
            ->when($task->resolution_delivered_comment_id !== null, static fn ($query) => $query->where('task_comment_id', $task->resolution_delivered_comment_id))
            ->latest('id')
            ->first();
    }

    /** Recheck immediately before spawning, including retries of an already running fixup. */
    private function skipStaleConflictFixup(Task $task): bool
    {
        $feedback = is_string($task->fixup_problem) && str_starts_with($task->fixup_problem, 'review:');
        if ($this->conflictBase($task) === null && ! $feedback) {
            return false;
        }
        $group = $task->parent()->with(['project', 'taskable'])->firstOrFail();
        $health = $this->pullRequestWatcher->health($group);
        if (! $health instanceof TaskPullRequestHealth) {
            return true;
        }
        if ($health->state === 'open' && ($health->conflicts || $feedback)) {
            return false;
        }
        $reason = match ($health->state) {
            'merged' => $feedback ? 'Cancelled because the pull request merged; no feedback fixup can proceed.' : 'Cancelled because the pull request merged; no conflict fixup is needed.',
            'closed' => $feedback ? 'Cancelled because the pull request closed without merging; no feedback fixup can proceed.' : 'Cancelled because the pull request closed without merging; no conflict fixup can proceed.',
            default => $health->mergeable === true
                ? 'Cancelled because the pull request is mergeable again; no conflict fixup is needed.'
                : null,
        };
        if ($reason === null) {
            return true;
        }

        $settled = DB::transaction(function () use ($task, $group, $reason): ?Task {
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedGroup->status !== TaskGroupStatus::Running
                || $locked->status !== TaskStatus::Running || $this->implementerTurnStarted($locked)) {
                return null;
            }
            $locked->update([
                'status' => TaskStatus::Cancelled,
                'settled_at' => now(),
                'completion_summary' => $reason,
                ...TaskAssistance::cleared(),
            ]);
            $lockedGroup->update(['status' => TaskGroupStatus::Settling]);

            return $lockedGroup;
        });
        if ($settled instanceof Task) {
            // Reuse the observed terminal state so a missed approval is held before merge cleanup.
            $this->checkReturningPullRequest($settled, $health);
            $this->settle($settled, checkReturningPullRequest: false);
        }

        return true;
    }

    private function assignImplementer(Task $task, bool $alreadyFetched = false): void
    {
        TaskExecutionHold::run($task->parent, fn () => $this->assignAdmittedImplementer($task, $alreadyFetched));
    }

    private function assignAdmittedImplementer(Task $task, bool $alreadyFetched = false): void
    {
        if ($task->status !== TaskStatus::Running || $this->skipStaleConflictFixup($task)) {
            return;
        }

        $this->recordSubtaskStart($task);
        $group = $task->parent()->with(['project', 'taskable'])->first();
        if ($group instanceof Task && ! $this->validateImplementerDeliverablePaths($task, $group)) {
            return;
        }

        $threadId = null;
        try {
            if ($group instanceof Task) {
                $reserved = $task->implementer_agent_thread_id;
                if ($reserved === null && $this->spawner instanceof TaskAgentSpawner) {
                    $reserved = $this->spawner->reserveImplementer($task->fresh() ?? $task);
                }
                if (! $this->prepareTurn($group, $task, TaskThreadRole::Implementer, $reserved === null ? null : (int) $reserved, $alreadyFetched)) {
                    return;
                }
                $threadId = $this->spawner->spawnImplementer($task->fresh() ?? $task);
            }
        } catch (TaskTurnReceiptException $exception) {
            Log::error('The turn command could not be installed for the implementer.', ['task_id' => $task->id, 'reason' => $exception->getMessage()]);
        }

        if ($threadId === null) {
            $this->failSpawn($group, $task, 'implementer');

            return;
        }

        $task->implementer_agent_thread_id = $threadId;
        $task->save();
    }

    /** No thread is reserved or spawned until its deliverables pass on the actual review base. */
    private function validateImplementerDeliverablePaths(Task $task, Task $group): bool
    {
        $base = TaskReviewBase::commit($task);
        if ($base === '') {
            $this->requestAssistance($task, $group, TaskGroupGuard::DeliverableGatePrefix.'cannot start the implementer: the review base is unresolved.');

            return false;
        }

        try {
            $workspace = $group->taskable instanceof Instance ? $group->taskable : null;
            $errors = $this->deliverablePaths->check($group->project, $task->deliverables ?? [], $base, 'resolved', $workspace);
        } catch (ResourceOperationException $exception) {
            $this->requestAssistance($task, $group, TaskGroupGuard::DeliverableGatePrefix."could not read base {$base}: ".$exception->getMessage());

            return false;
        }
        if ($errors !== []) {
            $this->requestAssistance($task, $group, TaskGroupGuard::DeliverableGatePrefix.'failed before implementer start. '.implode(' ', $errors));

            return false;
        }

        return true;
    }

    /**
     * Records the workspace HEAD as this subtask's start. A failed read stays empty so a later tick
     * can try again, until the implementer's first turn starts. After that turn, a later HEAD would
     * hide the implementer's commits, so the start stays empty and the review uses its fallback base.
     * A start that is already recorded is not replaced.
     */
    private function recordSubtaskStart(Task $task): void
    {
        if (is_string($task->subtask_start_commit) && $task->subtask_start_commit !== '') {
            return;
        }
        if ($task->continuation_of_task_id !== null) {
            $source = Task::query()->find($task->continuation_of_task_id);
            if ($source instanceof Task) {
                $sourceStart = TaskReviewBase::commit($source);
                if ($sourceStart !== '') {
                    $task->update(['subtask_start_commit' => $sourceStart]);
                }

                return;
            }
        }
        if ($this->implementerTurnStarted($task)) {
            return;
        }
        $instance = $task->parent()->with('taskable')->first()?->taskable;
        if (! $instance instanceof Instance) {
            return;
        }
        $head = $this->workspace->headCommit($instance);
        if (! is_string($head) || $head === '') {
            return;
        }
        $task->update(['subtask_start_commit' => $head]);
    }

    private function markSubtaskCancelled(Task $task): ?string
    {
        TaskCheck::query()->where('task_id', $task->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->update(['status' => TaskCheckStatus::Cancelled->value, 'finished_at' => now(), 'updated_at' => now()]);

        $assistanceReason = $task->assistance_reason;
        $task->update([
            'status' => TaskStatus::Cancelled,
            'settled_at' => now(),
            'completion_summary' => 'Cancelled by operator.',
            ...TaskAssistance::cleared(),
        ]);

        return $assistanceReason;
    }

    private function clearCancelledSubtaskAssistance(Task $group, Task $task, ?string $assistanceReason): void
    {
        if (RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)
            || ! $group->assistance_requested || $assistanceReason === null || $group->assistance_reason !== $assistanceReason) {
            return;
        }

        $otherAssistance = $group->tasks()
            ->whereKeyNot($task->id)
            ->where('assistance_requested', true)
            ->exists();
        if (! $otherAssistance) {
            $group->fill(TaskAssistance::cleared());
        }
    }

    /** @param  Collection<int, Task>  $tasks */
    private function hasOpenSubtask(Collection $tasks): bool
    {
        return $tasks->contains(static fn (Task $task): bool => in_array($task->status, [
            TaskStatus::Todo,
            TaskStatus::Reserved,
            TaskStatus::Running,
            TaskStatus::Reviewing,
        ], true));
    }

    /** @param  Collection<int, Task>  $tasks */
    private function statusWithOpenSubtasks(Task $group, Collection $tasks): TaskGroupStatus
    {
        if ($this->runningSibling($tasks) instanceof Task || $tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Running)) {
            return TaskGroupStatus::Running;
        }
        if ($tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Reviewing)) {
            return TaskGroupStatus::Reviewing;
        }
        if ($tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Reserved)) {
            return TaskGroupStatus::Reserved;
        }

        return $group->groupStatus();
    }

    /** @return Collection<int, Task> */
    private function lockedTasks(Task $group): Collection
    {
        $tasks = Task::query()
            ->where('parent_id', $group->id)
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

    private function failSpawn(?Task $group, ?Task $task, string $agent): void
    {
        if ($task instanceof Task) {
            $task->status = TaskStatus::Failed;
            $task->save();
        }

        if ($group instanceof Task) {
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
