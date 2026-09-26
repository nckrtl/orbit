<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\AgentThread;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

/**
 * Collects the task changes of one request, scheduler tick, or agent view pass and broadcasts each
 * record once when that work ends (ADR 0151). The events are notices: ids, status, and counts, never
 * titles, briefs, receipts, or messages, so a task group never passes Reverb's message limit.
 */
final class TaskBroadcasts
{
    /** Columns whose change alone never broadcasts, because showing a group refreshes them. */
    public const array MetricColumns = ['tokens', 'line_diff', 'lines_added', 'lines_deleted', 'duration_ms', 'updated_at'];

    /** Agent thread columns whose change broadcasts `agent_thread.updated`. */
    public const array ThreadColumns = ['state', 'error', 'observation_error'];

    /** @var array<int, bool> Group ids, true when the group was created in this unit of work. */
    private array $groups = [];

    /** @var array<int, true> */
    private array $comments = [];

    /** @var array<int, true> */
    private array $threads = [];

    private ?bool $extension = null;

    public function __construct(private readonly RecordEventBroadcaster $broadcaster) {}

    public function groupCreated(int $groupId): void
    {
        $this->groups[$groupId] = true;
    }

    public function groupChanged(int $groupId): void
    {
        $this->groups[$groupId] ??= false;
    }

    public function commentCreated(int $commentId): void
    {
        $this->comments[$commentId] = true;
    }

    public function threadChanged(int $threadId): void
    {
        $this->threads[$threadId] = true;
    }

    public function extensionChanged(bool $enabled): void
    {
        $this->extension = $enabled;
    }

    /**
     * Whether a change to these columns broadcasts a group update.
     *
     * @param  list<string>  $columns
     */
    public static function broadcastsGroupChange(array $columns): bool
    {
        return array_diff($columns, self::MetricColumns) !== [];
    }

    /**
     * Whether a change to these columns broadcasts a thread update.
     *
     * @param  list<string>  $columns
     */
    public static function broadcastsThreadChange(array $columns): bool
    {
        return array_intersect($columns, self::ThreadColumns) !== [];
    }

    /**
     * Broadcasts every collected change once, from the records as they are now. Inside a database
     * transaction it waits for the commit, and a rollback discards the changes.
     */
    public function flush(): void
    {
        $changes = [$this->groups, $this->comments, $this->threads, $this->extension];
        $this->groups = $this->comments = $this->threads = [];
        $this->extension = null;

        if ($changes === [[], [], [], null]) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->send(...$changes));

            return;
        }

        $this->send(...$changes);
    }

    /**
     * @param  array<int, bool>  $groups
     * @param  array<int, true>  $comments
     * @param  array<int, true>  $threads
     */
    private function send(array $groups, array $comments, array $threads, ?bool $extension): void
    {
        if ($groups !== []) {
            foreach (TaskGroup::query()->whereKey(array_keys($groups))->orderBy('id')->get() as $group) {
                $this->broadcaster->broadcast(
                    $groups[$group->id] ? RecordEventType::TaskGroupCreated : RecordEventType::TaskGroupUpdated,
                    $group->id,
                    $groups[$group->id]
                        ? ['id' => $group->id, 'status' => $group->status->value]
                        : ['id' => $group->id, 'status' => $group->status->value, 'lines_added' => $group->lines_added, 'lines_deleted' => $group->lines_deleted, 'line_diff' => $group->line_diff],
                );
            }
        }

        if ($comments !== []) {
            foreach (TaskComment::query()->whereKey(array_keys($comments))->orderBy('id')->get() as $comment) {
                $this->broadcaster->broadcast(RecordEventType::TaskCommentCreated, $comment->id, [
                    'id' => $comment->id, 'task_group_id' => $comment->task_group_id, 'task_id' => $comment->task_id,
                ]);
            }
        }

        if ($threads !== []) {
            foreach (AgentThread::query()->whereKey(array_keys($threads))->orderBy('id')->get() as $thread) {
                $this->broadcaster->broadcast(RecordEventType::AgentThreadUpdated, $thread->id, [
                    'id' => $thread->id, 'task_group_id' => $thread->task_group_id, 'task_id' => $thread->task_id, 'state' => $thread->state?->value,
                ]);
            }
        }

        if ($extension !== null) {
            $this->broadcaster->broadcast(RecordEventType::TasksUpdated, 0, ['enabled' => $extension]);
        }
    }
}
