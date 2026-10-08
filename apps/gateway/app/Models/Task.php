<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskLevelStatusCast;
use App\Domain\Tasks\TaskMergeStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use BackedEnum;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property-read AgentThread|null $implementerThread
 * @property-read Task|null $parent
 * @property TaskType $type
 * @property string|null $target_thread_id
 * @property string|null $completion_summary
 * @property int $id
 * @property int|null $parent_id
 * @property int $position
 * @property int|null $continuation_of_task_id
 * @property int $completion_attempt
 * @property int|null $completion_handoff_comment_id
 * @property int|null $completion_reminder_attempt
 * @property string|null $completion_reminder_input_id
 * @property int|null $completion_handoff_attempt
 * @property string|null $completion_handoff_turn_id
 * @property int $review_attempt
 * @property int|null $review_handled_comment_id
 * @property int|null $review_reminder_attempt
 * @property string|null $review_reminder_input_id
 * @property int|null $review_notified_attempt
 * @property string|null $review_notified_turn_id
 * @property string|null $review_workspace_head
 * @property string|null $review_workspace_tree
 * @property bool $assistance_requested
 * @property AssistanceKind|null $assistance_kind
 * @property string|null $assistance_question
 * @property string|null $assistance_reason
 * @property int $communication_failures
 * @property int|null $ended_pr_notice_thread_id
 * @property string|null $ended_pr_notice_key
 * @property string|null $ended_pr_notice_state
 * @property int $pi_restart_resumes
 * @property string|null $pi_restart_key
 * @property int|null $pi_restart_thread_id
 * @property string|null $pi_restart_source_turn_id
 * @property string|null $pi_restart_reservation
 * @property string|null $pi_restart_session_revision
 * @property int|null $resolution_delivered_comment_id
 * @property string $title
 * @property string $brief
 * @property TaskStatus|TaskGroupStatus $status
 * @property int $project_id
 * @property string|null $taskable_type
 * @property int|null $taskable_id
 * @property string|null $pr_url
 * @property string|null $pr_branch
 * @property TaskMergeStatus|null $merge_status
 * @property string|null $merge_reason
 * @property string|null $merged_sha
 * @property string|null $watched_pr_url
 * @property int|null $watched_pr_number
 * @property string|null $watched_pr_state
 * @property string|null $watched_pr_completion
 * @property bool|null $preview
 * @property bool $notify_coder
 * @property string $implementer_model
 * @property string $reviewer_model
 * @property int|null $reviewer_agent_thread_id
 * @property string|null $capacity_wait_reason
 * @property TaskCompute|null $task_compute
 * @property TaskExecutionMode $execution_mode
 * @property string $implementer_agent_driver
 * @property string $reviewer_agent_driver
 * @property Carbon|null $agent_unavailable_since
 * @property Carbon|null $agent_unavailable_notified_at
 * @property Carbon|null $reserved_at
 * @property int|null $implementer_agent_thread_id
 * @property int|null $tokens
 * @property int|null $lines_added
 * @property int|null $lines_deleted
 * @property int|null $line_diff
 * @property int|null $duration_ms
 * @property int $questions
 * @property int $escalations
 * @property int|null $direction_relay_comment_id
 * @property int|null $consult_comment_id
 * @property string|null $direction_answer_key
 * @property string|null $direction_answer_source_turn_id
 * @property Carbon|null $started_at
 * @property string|null $subtask_start_commit
 * @property string|null $fixup_problem
 * @property string|null $fixup_head_sha
 * @property list<string>|null $topology
 * @property list<array<string, string|bool|list<string>>>|null $deliverables
 * @property Carbon|null $settled_at
 * @property-read Task $parent
 * @property-read Collection<int, Task> $children
 * @property-read Collection<int, Task> $tasks
 * @property-read Project $project
 * @property-read Instance|Model|null $taskable
 * @property-read AgentThread|null $reviewerThread
 */
#[ObservedBy([TaskBroadcastObserver::class])]
final class Task extends Model
{
    /** @var list<string> */
    private const array TOP_LEVEL_COLUMNS = [
        'watched_pr_completion',
        'watched_pr_url',
        'watched_pr_number',
        'watched_pr_state',
        'project_id',
        'taskable_type',
        'taskable_id',
        'pr_url',
        'preview',
        'notify_coder',
        'implementer_model',
        'reviewer_model',
        'reviewer_agent_thread_id',
        'execution_mode',
        'implementer_agent_driver',
        'reviewer_agent_driver',
        'agent_unavailable_since',
        'agent_unavailable_notified_at',
        'reserved_at',
        'task_compute',
        'capacity_wait_reason',
        'pr_branch',
        'merge_status',
        'merge_reason',
        'merged_sha',
    ];

    /** @var list<string> */
    private const array SUBTASK_COLUMNS = [
        'ended_pr_notice_thread_id',
        'ended_pr_notice_key',
        'ended_pr_notice_state',
        'position',
        'implementer_agent_thread_id',
        'type',
        'target_thread_id',
        'completion_summary',
        'subtask_start_commit',
        'completion_attempt',
        'completion_handoff_comment_id',
        'completion_reminder_attempt',
        'completion_reminder_input_id',
        'completion_handoff_attempt',
        'completion_handoff_turn_id',
        'review_attempt',
        'review_handled_comment_id',
        'review_reminder_attempt',
        'review_reminder_input_id',
        'review_notified_attempt',
        'review_notified_turn_id',
        'review_workspace_head',
        'review_workspace_tree',
        'deliverables',
        'topology',
        'fixup_problem',
        'fixup_head_sha',
        'communication_failures',
        'resolution_delivered_comment_id',
        'direction_relay_comment_id',
        'consult_comment_id',
        'direction_answer_key',
        'direction_answer_source_turn_id',
        'pi_restart_resumes',
        'pi_restart_key',
        'pi_restart_thread_id',
        'pi_restart_source_turn_id',
        'pi_restart_reservation',
        'pi_restart_session_revision',
        'continuation_of_task_id',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'watched_pr_completion',
        'watched_pr_url',
        'watched_pr_number',
        'watched_pr_state',
        'type', 'target_thread_id', 'completion_summary',
        'parent_id',
        'continuation_of_task_id',
        'position',
        'title',
        'brief',
        'status',
        'implementer_agent_thread_id',
        'tokens',
        'questions',
        'escalations',
        'line_diff',
        'lines_added',
        'lines_deleted',
        'duration_ms',
        'started_at',
        'subtask_start_commit',
        'fixup_problem',
        'fixup_head_sha',
        'deliverables',
        'topology',
        'settled_at',
        'completion_attempt',
        'completion_handoff_comment_id',
        'completion_reminder_attempt',
        'completion_reminder_input_id',
        'completion_handoff_attempt',
        'completion_handoff_turn_id',
        'review_attempt',
        'review_handled_comment_id',
        'review_reminder_attempt',
        'review_reminder_input_id',
        'review_notified_attempt',
        'review_notified_turn_id',
        'review_workspace_head',
        'review_workspace_tree',
        'assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason', 'communication_failures', 'resolution_delivered_comment_id', 'direction_relay_comment_id', 'consult_comment_id', 'direction_answer_key', 'direction_answer_source_turn_id',
        'ended_pr_notice_thread_id', 'ended_pr_notice_key', 'ended_pr_notice_state',
        'pi_restart_resumes', 'pi_restart_key', 'pi_restart_thread_id', 'pi_restart_source_turn_id', 'pi_restart_reservation', 'pi_restart_session_revision',
        'project_id',
        'taskable_type',
        'taskable_id',
        'pr_url',
        'pr_branch',
        'merge_status',
        'merge_reason',
        'merged_sha',
        'preview',
        'notify_coder',
        'implementer_model',
        'reviewer_model',
        'reviewer_agent_thread_id',
        'execution_mode',
        'implementer_agent_driver',
        'reviewer_agent_driver',
        'agent_unavailable_since',
        'agent_unavailable_notified_at',
        'reserved_at',
        'task_compute',
        'capacity_wait_reason',
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::addGlobalScope('subtask', static function (Builder $query): void {
            $query->whereNotNull('parent_id');
        });

        self::saving(static function (Task $task): void {
            if ($task->exists && $task->getRawOriginal('task_compute') !== null && $task->isDirty('task_compute')) {
                throw new LogicException('A claimed task group cannot change compute mode.');
            }
            $task->ensureParentId();
            $task->applyLevelDefaults();
            $task->guardHierarchy();
            $task->guardStatus();
            $task->clearAssistanceWhenEnded();
        });
    }

    /**
     * A top-level task has a loaded null parent. A subtask has a loaded parent id.
     * The status cast and the broadcast observer both use this.
     */
    public function isTopLevel(): bool
    {
        return $this->resolvedParentId() === null;
    }

    public function subtaskStatus(): TaskStatus
    {
        $status = $this->status;

        if (! $status instanceof TaskStatus) {
            throw new LogicException('A subtask status must use the subtask status names.');
        }

        return $status;
    }

    public function groupStatus(): TaskGroupStatus
    {
        $status = $this->status;

        if (! $status instanceof TaskGroupStatus) {
            throw new LogicException('A top-level task status must use the top-level status names.');
        }

        return $status;
    }

    public function requireGroupId(): int
    {
        $groupId = $this->resolvedParentId();

        if (! is_int($groupId)) {
            throw new LogicException('A subtask is missing its task.');
        }

        return $groupId;
    }

    /**
     * ADR 0203: subtasks that are not final reviews. A legacy row may have no type.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeWithoutFinalReviews(Builder $query): void
    {
        $query->where(static fn (Builder $type) => $type->whereNull('type')->orWhere('type', '!=', TaskType::FinalReview->value));
    }

    /** @param  Builder<Task>  $query */
    public function scopeTopLevel(Builder $query): void
    {
        $query->withoutGlobalScope('subtask')->whereNull('parent_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withoutGlobalScope('subtask');
    }

    /** @return HasMany<Task, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * Subtasks of a top-level task, in position order. Same rows as children().
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return MorphTo<Model, $this> */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<AgentThread, $this> */
    public function reviewerThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'reviewer_agent_thread_id');
    }

    public function requireManagedExecution(): void
    {
        if ($this->execution_mode !== TaskExecutionMode::Managed) {
            throw new ResourceOperationException('tasks.external_execution', 'This task uses an existing thread. Use its annotation controls instead of the managed lifecycle.', 409);
        }
    }

    /** ADR 0203: whether this top-level task holds every push until a final review approves it. */
    public function reviewsBeforePush(): bool
    {
        return $this->parent_id === null && $this->execution_mode === TaskExecutionMode::Managed
            && $this->project->reviewsAndMerges();
    }

    /** ADR 0203: an incoming pull request that Orbit reviews, not a pull request Orbit opened. */
    public function reviewsIncomingPullRequest(): bool
    {
        return $this->parent_id === null && is_string($this->pr_branch) && $this->pr_branch !== '';
    }

    public function isFinalReview(): bool
    {
        return $this->type === TaskType::FinalReview;
    }

    /** A subtask an operator added: not a Gateway fixup and not a final review. Its completion opens a new fixup window. */
    public function isOperatorWork(): bool
    {
        return $this->fixup_problem === null && ! $this->isFinalReview();
    }

    /** A fixup the settling watcher appended for a conflict, a failed check, or trusted feedback. */
    public function isSettlingFixup(): bool
    {
        return is_string($this->fixup_problem) && $this->fixup_problem !== '' && $this->fixup_problem !== TaskFinalReview::FixupProblem;
    }

    /** @return HasMany<TaskReviewedCommit, $this> */
    public function reviewedCommits(): HasMany
    {
        return $this->hasMany(TaskReviewedCommit::class, 'task_id')->orderBy('id');
    }

    /** @return BelongsTo<AgentThread, $this> */
    public function implementerThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'implementer_agent_thread_id');
    }

    /** @return HasMany<TaskCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(TaskCheck::class);
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * Whether no other subtask of the group is still to be done.
     */
    public function isLastSubtask(): bool
    {
        return self::query()
            ->where('parent_id', $this->requireGroupId())
            ->whereKeyNot($this->id)
            ->whereIn('status', [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running])
            ->doesntExist();
    }

    /**
     * Whether this approval opens the group's pull request.
     * After a pull request URL is stored, the approval pushes to that pull request and does not require pull request fields (ADR 0164).
     */
    public function opensPullRequest(): bool
    {
        // ADR 0203: in a review-and-merge task only the final review opens the pull request.
        if ($this->type === TaskType::FinalReview) {
            $url = $this->parent()->value('pr_url');

            return ! is_string($url) || $url === '';
        }
        if ($this->parent->reviewsBeforePush()) {
            return false;
        }
        if (! $this->isLastSubtask()) {
            return false;
        }

        $url = $this->parent()->value('pr_url');

        return ! is_string($url) || $url === '';
    }

    /**
     * ADR 0133: the typed items this subtask must deliver. Empty for subtasks created before deliverables existed.
     *
     * @return list<TaskDeliverable>
     */
    public function deliverableList(): array
    {
        return TaskDeliverable::listFrom($this->deliverables);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'position' => 'integer',
            'status' => TaskLevelStatusCast::class,
            'execution_mode' => TaskExecutionMode::class,
            'task_compute' => TaskCompute::class,
            'merge_status' => TaskMergeStatus::class,
            'preview' => 'boolean',
            'notify_coder' => 'boolean',
            'watched_pr_number' => 'integer',
            'agent_unavailable_since' => 'datetime',
            'agent_unavailable_notified_at' => 'datetime',
            'reserved_at' => 'datetime',
            'tokens' => 'integer',
            'line_diff' => 'integer',
            'lines_added' => 'integer',
            'lines_deleted' => 'integer',
            'duration_ms' => 'integer',
            'questions' => 'integer',
            'escalations' => 'integer',
            'direction_relay_comment_id' => 'integer',
            'consult_comment_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'deliverables' => 'array',
            'topology' => 'array',
            'completion_attempt' => 'integer',
            'completion_handoff_attempt' => 'integer',
            'completion_handoff_comment_id' => 'integer',
            'completion_reminder_attempt' => 'integer',
            'review_attempt' => 'integer',
            'review_handled_comment_id' => 'integer',
            'review_reminder_attempt' => 'integer',
            'review_notified_attempt' => 'integer',
            'assistance_requested' => 'boolean',
            'assistance_kind' => AssistanceKind::class,
            'communication_failures' => 'integer',
            'pi_restart_resumes' => 'integer',
            'pi_restart_thread_id' => 'integer',
            'ended_pr_notice_thread_id' => 'integer',
            'resolution_delivered_comment_id' => 'integer',
        ];
    }

    /**
     * The assistance flag follows the stored status when this update does not change it.
     * The decision is inside the UPDATE, so a stale loaded status cannot reflag an ended row.
     */
    private bool $assistanceFollowsDatabase = false;

    #[\Override]
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->guardEndedAssistance($this->getDirtyForUpdate());

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->refreshSavedAttributes();

            if ($this->assistanceFollowsDatabase) {
                $this->assistanceFollowsDatabase = false;
                $this->syncAssistanceFromDatabase();
            }

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $dirty
     * @return array<string, mixed>
     */
    private function guardEndedAssistance(array $dirty): array
    {
        if (array_key_exists('status', $dirty) && $this->isEndedStatus($dirty['status'])) {
            $this->assistance_requested = false;
            $dirty['assistance_requested'] = $this->attributes['assistance_requested'];

            return $dirty;
        }

        $requestsAssistance = array_key_exists('assistance_requested', $dirty)
            && $this->isRequestedFlag($dirty['assistance_requested']);
        $loadedEnded = ! array_key_exists('status', $dirty) && $this->isEndedStatus($this->attributes['status'] ?? null);

        if (! array_key_exists('status', $dirty) && ($requestsAssistance || $loadedEnded)) {
            $grammar = $this->getConnection()->getQueryGrammar();
            $status = $grammar->wrap('status');
            $flag = $requestsAssistance ? '1' : $grammar->wrap('assistance_requested');
            $dirty['assistance_requested'] = new Expression(
                "case when {$status} in ('completed', 'cancelled') then 0 else {$flag} end", // @phpstan-ignore argument.type (identifiers are grammar-wrapped, not user input)
            );
            $this->assistanceFollowsDatabase = true;
        }

        return $dirty;
    }

    private function syncAssistanceFromDatabase(): void
    {
        $stored = $this->newModelQuery()->whereKey($this->getKey())->value('assistance_requested');
        $this->assistance_requested = $this->isRequestedFlag($stored);
    }

    private function isEndedStatus(mixed $status): bool
    {
        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        return $status === TaskStatus::Completed->value || $status === TaskStatus::Cancelled->value;
    }

    private function isRequestedFlag(mixed $flag): bool
    {
        return in_array($flag, [true, 1, '1'], true);
    }

    private function applyLevelDefaults(): void
    {
        if ($this->parentKey() === null) {
            $this->fillIfMissing([
                'status' => TaskGroupStatus::Backlog->value,
                'execution_mode' => TaskExecutionMode::Managed->value,
                'implementer_agent_driver' => 'pi',
                'reviewer_agent_driver' => 'pi',
                'preview' => false,
                'notify_coder' => false,
                'assistance_requested' => false,
                'implementer_model' => TaskAgentDefaults::ImplementerModel,
                'reviewer_model' => TaskAgentDefaults::ReviewerModel,
            ]);

            return;
        }

        $this->fillIfMissing([
            'status' => TaskStatus::Todo->value,
            'type' => TaskType::Implementation->value,
            'assistance_requested' => false,
            'pi_restart_resumes' => 0,
            'completion_attempt' => 1,
            'review_attempt' => 1,
            'communication_failures' => 0,
        ]);
    }

    /** @param  array<string, mixed>  $defaults */
    private function fillIfMissing(array $defaults): void
    {
        foreach ($defaults as $column => $value) {
            if (! array_key_exists($column, $this->attributes) || $this->attributes[$column] === null) {
                $this->setAttribute($column, $value);
            }
        }
    }

    private function guardHierarchy(): void
    {
        $parentId = $this->parentKey();

        if ($parentId === null) {
            $this->rejectColumns(self::SUBTASK_COLUMNS, 'top-level task');

            return;
        }

        $this->rejectColumns(self::TOP_LEVEL_COLUMNS, 'subtask');

        $key = self::integerOrNull($this->getKey());

        if ($this->exists && $key === $parentId) {
            throw TaskHierarchyException::nested();
        }

        $parent = DB::table('tasks')->where('id', $parentId)->first(['id', 'parent_id']);

        if ($parent === null || $parent->parent_id !== null) {
            throw TaskHierarchyException::nested();
        }

        if ($this->exists && $this->children()->exists()) {
            throw TaskHierarchyException::nested();
        }
    }

    /** @param  list<string>  $columns */
    private function rejectColumns(array $columns, string $level): void
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $this->attributes) && $this->attributes[$column] !== null) {
                throw TaskHierarchyException::column($column, $level);
            }
        }
    }

    /**
     * The parent id, loaded from the row when a partial select omitted it.
     * A model that has neither the attribute nor a row fails instead of guessing a level.
     */
    private function resolvedParentId(): ?int
    {
        if (! array_key_exists('parent_id', $this->attributes)) {
            $this->loadParentId();
        }

        return self::integerOrNull($this->attributes['parent_id']);
    }

    /**
     * A new row that omits parent_id stores null. Record that before the level is read.
     * A saved row loads the stored value instead of treating the missing attribute as either level.
     */
    private function ensureParentId(): void
    {
        if (array_key_exists('parent_id', $this->attributes)) {
            return;
        }

        if ($this->exists) {
            $this->loadParentId();

            return;
        }

        $this->attributes['parent_id'] = null;
    }

    private function loadParentId(): void
    {
        if (! $this->exists) {
            throw new LogicException('A task needs parent_id before its level can be decided.');
        }

        $key = $this->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException('A task needs its id before its level can be decided.');
        }

        $row = DB::table($this->getTable())->where('id', $key)->first(['id', 'parent_id']);

        if ($row === null) {
            throw new LogicException("Task [{$key}] has no row, so its level cannot be decided.");
        }

        $stored = (array) $row;

        if (! array_key_exists('parent_id', $stored)) {
            throw new LogicException("Task [{$key}] has no row, so its level cannot be decided.");
        }

        $this->attributes['parent_id'] = $stored['parent_id'];
        $this->syncOriginalAttribute('parent_id');
    }

    /**
     * A completed or cancelled task never asks for assistance. The last reason stays.
     * A query-builder update skips this hook, so that write clears the flag itself.
     */
    private function clearAssistanceWhenEnded(): void
    {
        $status = $this->attributes['status'] ?? null;

        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        if (! is_string($status)) {
            return;
        }

        $ended = $this->resolvedParentId() === null
            ? in_array(TaskGroupStatus::tryFrom($status), [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled], true)
            : in_array(TaskStatus::tryFrom($status), [TaskStatus::Completed, TaskStatus::Cancelled], true);

        if ($ended) {
            $this->assistance_requested = false;
        }
    }

    private function guardStatus(): void
    {
        $status = $this->attributes['status'] ?? null;

        if (! is_string($status) || $status === '') {
            return;
        }

        if ($this->resolvedParentId() === null) {
            TaskGroupStatus::from($status);

            return;
        }

        TaskStatus::from($status);
    }

    private function parentKey(): ?int
    {
        return $this->resolvedParentId();
    }

    private static function integerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
