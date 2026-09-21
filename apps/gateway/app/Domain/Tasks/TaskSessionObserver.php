<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class TaskSessionObserver
{
    private const int ExcerptLimit = 400;

    public function __construct(
        private T3ThreadReader $threads,
        private TaskWorkspaceDiffReader $diff,
    ) {}

    public function observe(TaskGroup $group): TaskSessionObservation
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);

        $wanted = $this->taskThreadIds($group);
        $node = $this->node($group);
        $hasNewCommits = $this->hasNewCommits($group);
        $threads = [];

        foreach ($wanted as $threadId => $role) {
            $snapshot = $node instanceof Node ? $this->threads->snapshot($node, $threadId) : null;
            $threads[] = $this->observation(
                $threadId,
                $role,
                is_array($snapshot) ? $snapshot : [],
                $hasNewCommits,
                $group->pr_url,
            );
        }

        return new TaskSessionObservation(
            groupId: $group->id,
            groupStatus: $group->status->value,
            title: $group->title,
            brief: $group->brief,
            hasPendingSubtasks: $group->tasks->contains(
                static fn (Task $task): bool => in_array($task->status, [
                    TaskStatus::Pending,
                    TaskStatus::Reserved,
                    TaskStatus::Running,
                    TaskStatus::Reviewing,
                ], true),
            ),
            prUrl: $this->string($group->pr_url),
            ciSummary: null,
            threads: $threads,
        );
    }

    /**
     * @return array<string, TaskThreadRole>
     */
    private function taskThreadIds(TaskGroup $group): array
    {
        $wanted = [];

        if (is_string($group->reviewer_thread_id) && $group->reviewer_thread_id !== '') {
            $wanted[$group->reviewer_thread_id] = TaskThreadRole::Reviewer;
        }

        foreach ($group->tasks as $task) {
            if (is_string($task->implementer_thread_id) && $task->implementer_thread_id !== '') {
                $wanted[$task->implementer_thread_id] = TaskThreadRole::Implementer;
            }
        }

        /** @var Collection<int, TaskAgentSession> $sessions */
        $sessions = TaskAgentSession::query()
            ->where('task_group_id', $group->id)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $role = TaskThreadRole::tryFrom($session->role);

            if ($role instanceof TaskThreadRole && $session->thread_id !== '') {
                $wanted[$session->thread_id] = $role;
            }
        }

        return $wanted;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function observation(
        string $threadId,
        TaskThreadRole $role,
        array $snapshot,
        bool $hasNewCommits,
        ?string $prUrl,
    ): TaskThreadObservation {
        $thread = $this->threadPayload($snapshot);
        $sessState = $this->sessState($thread);
        $pendingApprovalId = $this->pendingRequestId($thread, 'approval');
        $pendingUserInputId = $this->pendingRequestId($thread, 'user-input')
            ?? $this->pendingRequestId($thread, 'user_input');
        $idle = $this->isIdle($sessState, $thread, $pendingApprovalId, $pendingUserInputId);

        return new TaskThreadObservation(
            threadId: $threadId,
            role: $role,
            sessState: $sessState,
            idle: $idle,
            pendingApprovalId: $pendingApprovalId,
            pendingUserInputId: $pendingUserInputId,
            lastAssistantText: $this->lastMessageText($thread, 'assistant'),
            lastUserText: $this->lastMessageText($thread, 'user'),
            hasNewCommitsSinceThreadStart: $hasNewCommits,
            prUrl: $this->string($prUrl),
            ciSummary: $this->string($thread['ciSummary'] ?? $thread['ci_summary'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function threadPayload(array $snapshot): array
    {
        $thread = $snapshot['thread'] ?? $snapshot;

        return is_array($thread) ? $thread : [];
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function sessState(array $thread): string
    {
        $session = $thread['session'] ?? $thread['sess'] ?? null;

        if (is_array($session)) {
            $status = $session['status'] ?? $session['state'] ?? null;

            if (is_string($status) && $status !== '') {
                return $status;
            }
        }

        $latestTurn = $thread['latestTurn'] ?? $thread['latest_turn'] ?? null;

        if (is_array($latestTurn) && is_string($latestTurn['state'] ?? null) && $latestTurn['state'] !== '') {
            return $latestTurn['state'];
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function isIdle(string $sessState, array $thread, ?string $pendingApprovalId, ?string $pendingUserInputId): bool
    {
        if ($pendingApprovalId !== null || $pendingUserInputId !== null) {
            return false;
        }

        if (in_array($sessState, ['idle', 'ready', 'stopped', 'interrupted'], true)) {
            return true;
        }

        $latestTurn = $thread['latestTurn'] ?? $thread['latest_turn'] ?? null;

        return is_array($latestTurn) && ($latestTurn['state'] ?? null) === 'completed';
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function pendingRequestId(array $thread, string $kind): ?string
    {
        $listKey = $kind === 'approval' ? ['pendingApprovals', 'pending_approvals'] : ['pendingUserInputs', 'pending_user_inputs'];

        foreach ($listKey as $key) {
            $items = $thread[$key] ?? null;

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }

                if (is_array($item)) {
                    $id = $this->string($item['requestId'] ?? $item['request_id'] ?? $item['id'] ?? null);

                    if ($id !== null) {
                        return $id;
                    }
                }
            }
        }

        foreach ($this->activities($thread) as $activity) {
            $activityKind = strtolower((string) ($activity['kind'] ?? $activity['tone'] ?? ''));

            if (! str_contains($activityKind, $kind) && ! ($kind === 'approval' && ($activity['tone'] ?? null) === 'approval')) {
                continue;
            }

            if (str_contains($activityKind, 'respond') || str_contains($activityKind, 'resolved')) {
                continue;
            }

            $payload = is_array($activity['payload'] ?? null) ? $activity['payload'] : [];
            $id = $this->string($payload['requestId'] ?? $payload['request_id'] ?? $activity['requestId'] ?? $activity['request_id'] ?? $activity['id'] ?? null);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $thread
     * @return list<array<string, mixed>>
     */
    private function activities(array $thread): array
    {
        $activities = $thread['activities'] ?? [];

        if (! is_array($activities)) {
            return [];
        }

        $rows = [];

        foreach ($activities as $activity) {
            if (is_array($activity)) {
                $rows[] = $activity;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function lastMessageText(array $thread, string $role): ?string
    {
        $messages = $thread['messages'] ?? [];

        if (! is_array($messages)) {
            return null;
        }

        $text = null;

        foreach ($messages as $message) {
            if (! is_array($message) || ($message['role'] ?? null) !== $role) {
                continue;
            }

            $candidate = $this->string($message['text'] ?? $message['content'] ?? null);

            if ($candidate !== null) {
                $text = $candidate;
            }
        }

        if ($text === null) {
            foreach ($this->activities($thread) as $activity) {
                $payload = is_array($activity['payload'] ?? null) ? $activity['payload'] : [];
                $activityRole = $payload['role'] ?? $activity['role'] ?? null;

                if ($activityRole !== $role) {
                    continue;
                }

                $candidate = $this->string($payload['text'] ?? $activity['summary'] ?? null);

                if ($candidate !== null) {
                    $text = $candidate;
                }
            }
        }

        return $text === null ? null : Str::limit($text, self::ExcerptLimit, '');
    }

    private function hasNewCommits(TaskGroup $group): bool
    {
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance) {
            return false;
        }

        $since = $instance->starting_commit;

        if (! is_string($since) || $since === '') {
            $started = $group->started_at ?? $group->tasks->first()?->started_at;
            $since = $started instanceof Carbon ? $started->toIso8601String() : null;
        }

        return is_string($since) && $since !== '' && $this->diff->hasCommitsSince($instance, $since);
    }

    private function node(TaskGroup $group): ?Node
    {
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance) {
            return null;
        }

        $instance->loadMissing('node');

        return $instance->node;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
