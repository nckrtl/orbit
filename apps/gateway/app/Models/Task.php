<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskColumnQueryBuilder;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskLevelStatusCast;
use App\Domain\Tasks\TaskSchema;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * @property int $task_group_id
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
 * @property string|null $assistance_reason
 * @property int $communication_failures
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
 * @property bool $notify_coder
 * @property string $implementer_model
 * @property string $reviewer_model
 * @property int|null $reviewer_agent_thread_id
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
 * @property Carbon|null $started_at
 * @property string|null $subtask_start_commit
 * @property string|null $fixup_problem
 * @property string|null $fixup_head_sha
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
        'project_id',
        'taskable_type',
        'taskable_id',
        'pr_url',
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
    ];

    /** @var list<string> */
    private const array SUBTASK_COLUMNS = [
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
        'fixup_problem',
        'fixup_head_sha',
        'communication_failures',
        'resolution_delivered_comment_id',
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
        'type', 'target_thread_id', 'completion_summary',
        'parent_id',
        'task_group_id',
        'continuation_of_task_id',
        'position',
        'title',
        'brief',
        'status',
        'implementer_agent_thread_id',
        'tokens',
        'line_diff',
        'lines_added',
        'lines_deleted',
        'duration_ms',
        'started_at',
        'subtask_start_commit',
        'fixup_problem',
        'fixup_head_sha',
        'deliverables',
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
        'assistance_requested', 'assistance_reason', 'communication_failures', 'resolution_delivered_comment_id',
        'pi_restart_resumes', 'pi_restart_key', 'pi_restart_thread_id', 'pi_restart_source_turn_id', 'pi_restart_reservation', 'pi_restart_session_revision',
        'project_id',
        'taskable_type',
        'taskable_id',
        'pr_url',
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
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::addGlobalScope('subtask', static function (Builder $query): void {
            if (! TaskSchema::merged($query->getModel()->getConnection())) {
                return;
            }

            $query->whereNotNull('parent_id');
        });

        self::saving(static function (Task $task): void {
            if (! TaskSchema::merged($task->getConnection())) {
                $task->fillIfMissing([
                    'status' => TaskStatus::Todo->value,
                    'assistance_requested' => false,
                    'pi_restart_resumes' => 0,
                    'type' => TaskType::Implementation->value,
                ]);

                return;
            }

            $task->applyLevelDefaults();
            $task->guardHierarchy();
        });
    }

    public function requireGroupId(): int
    {
        $groupId = $this->task_group_id;

        if (! is_int($groupId)) {
            throw new LogicException('A subtask is missing its task.');
        }

        return $groupId;
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
            ->where('task_group_id', $this->task_group_id)
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
            'notify_coder' => 'boolean',
            'agent_unavailable_since' => 'datetime',
            'agent_unavailable_notified_at' => 'datetime',
            'reserved_at' => 'datetime',
            'tokens' => 'integer',
            'line_diff' => 'integer',
            'lines_added' => 'integer',
            'lines_deleted' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'deliverables' => 'array',
            'completion_attempt' => 'integer',
            'completion_handoff_attempt' => 'integer',
            'completion_handoff_comment_id' => 'integer',
            'completion_reminder_attempt' => 'integer',
            'review_attempt' => 'integer',
            'review_handled_comment_id' => 'integer',
            'review_reminder_attempt' => 'integer',
            'review_notified_attempt' => 'integer',
            'assistance_requested' => 'boolean',
            'communication_failures' => 'integer',
            'pi_restart_resumes' => 'integer',
            'pi_restart_thread_id' => 'integer',
            'resolution_delivered_comment_id' => 'integer',
        ];
    }

    protected function newBaseQueryBuilder(): QueryBuilder
    {
        $connection = $this->getConnection();

        return new TaskColumnQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }

    /**
     * Subtask rows keep the task_group_id name. On the merged table it is parent_id.
     *
     * @return Attribute<?int, array<string, int|string|null>>
     */
    protected function taskGroupId(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                if (TaskSchema::merged($this->getConnection()) && array_key_exists('parent_id', $this->attributes)) {
                    return self::integerOrNull($this->attributes['parent_id']);
                }

                return self::integerOrNull($this->attributes['task_group_id'] ?? null);
            },
            set: function (int|string|null $value): array {
                $column = TaskSchema::merged($this->getConnection()) ? 'parent_id' : 'task_group_id';

                return [$column => $value];
            },
        );
    }

    private function applyLevelDefaults(): void
    {
        if ($this->parentKey() === null) {
            $this->fillIfMissing([
                'status' => TaskGroupStatus::Backlog->value,
                'execution_mode' => TaskExecutionMode::Managed->value,
                'implementer_agent_driver' => 't3',
                'reviewer_agent_driver' => 't3',
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

        if ($this->exists && DB::table('tasks')->where('parent_id', $this->getKey())->exists()) {
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

    private function parentKey(): ?int
    {
        if (! TaskSchema::merged($this->getConnection())) {
            return null;
        }

        return self::integerOrNull($this->attributes['parent_id'] ?? null);
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
