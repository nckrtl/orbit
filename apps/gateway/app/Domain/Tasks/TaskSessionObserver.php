<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Builds one structured observation of a task group from T3 thread snapshots,
 * the stored task rows, and the shared checkout.
 *
 * Only threads Orbit started for that group are read. The observer never
 * dispatches to T3, notifies Coder, or writes a row.
 */
final readonly class TaskSessionObserver
{
    private const int ExcerptLimit = 400;

    /** @var list<string> */
    private const array WaitingSessionStates = ['ready', 'stopped', 'idle'];

    /** @var list<string> */
    private const array FinishedTurnStates = ['completed', 'error', 'interrupted'];

    public function __construct(
        private T3ThreadReader $threads,
        private TaskWorkspaceCommitReader $commits,
    ) {}

    public function observe(TaskGroup $group): TaskSessionObservation
    {
        $group->loadMissing(['tasks', 'taskable']);

        $commitTimes = $this->commitTimes($group);
        $observed = [];
        $prUrl = $this->string($group->pr_url);
        $ciSummary = null;

        foreach ($this->threadReferences($group) as $reference) {
            $thread = $this->observeThread($group, $reference, $commitTimes);
            $observed[] = $thread;
            $prUrl ??= $thread->prUrl;
            $ciSummary ??= $thread->ciSummary;
        }

        return new TaskSessionObservation(
            groupId: $group->id,
            groupStatus: $group->status,
            currentTaskId: $this->currentTask($group)?->id,
            prUrl: $prUrl,
            ciSummary: $ciSummary,
            threads: $observed,
        );
    }

    /**
     * Stored task threads of this group, reviewer first, in subtask order.
     *
     * @return list<array{thread_id: string, role: TaskThreadRole, task: Task|null, node: Node|null}>
     */
    private function threadReferences(TaskGroup $group): array
    {
        $instanceNode = $this->node($group);
        $references = [];

        if (is_string($group->reviewer_thread_id) && $group->reviewer_thread_id !== '') {
            $references[$group->reviewer_thread_id] = [
                'thread_id' => $group->reviewer_thread_id,
                'role' => TaskThreadRole::Reviewer,
                'task' => null,
                'node' => $instanceNode,
            ];
        }

        foreach ($group->tasks as $task) {
            $threadId = $task->implementer_thread_id;

            if (! is_string($threadId) || $threadId === '' || isset($references[$threadId])) {
                continue;
            }

            $references[$threadId] = [
                'thread_id' => $threadId,
                'role' => TaskThreadRole::Implementer,
                'task' => $task,
                'node' => $instanceNode,
            ];
        }

        $sessions = TaskAgentSession::query()
            ->with('node')
            ->where('task_group_id', $group->id)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $role = TaskThreadRole::tryFrom($session->role);

            if (! $role instanceof TaskThreadRole || $session->thread_id === '') {
                continue;
            }

            $task = $this->task($group, $session->task_id, $session->thread_id);
            $node = $session->node ?? $instanceNode;

            if (isset($references[$session->thread_id])) {
                $references[$session->thread_id]['task'] ??= $task;
                $references[$session->thread_id]['node'] = $node;

                continue;
            }

            $references[$session->thread_id] = [
                'thread_id' => $session->thread_id,
                'role' => $role,
                'task' => $task,
                'node' => $node,
            ];
        }

        return array_values($references);
    }

    /**
     * @param  array{thread_id: string, role: TaskThreadRole, task: Task|null, node: Node|null}  $reference
     * @param  list<CarbonImmutable>|null  $commitTimes
     */
    private function observeThread(TaskGroup $group, array $reference, ?array $commitTimes): TaskThreadObservation
    {
        $node = $reference['node'];
        $snapshot = $node instanceof Node ? $this->threads->snapshot($node, $reference['thread_id']) : null;
        $thread = $this->threadPayload(is_array($snapshot) ? $snapshot : []);
        $pending = $this->pendingRequestIds($thread);
        $pullRequest = $this->pullRequest($thread);
        $task = $reference['task'];
        $startedAt = $task instanceof Task && $reference['role'] === TaskThreadRole::Implementer
            ? $task->started_at ?? $group->started_at
            : $group->started_at;

        return new TaskThreadObservation(
            threadId: $reference['thread_id'],
            role: $reference['role'],
            taskId: $task?->id,
            sessionState: $this->sessionState($thread),
            idle: $this->isIdle($thread, $pending),
            pendingApprovalId: $pending['approval'],
            pendingUserInputId: $pending['user_input'],
            lastAssistantText: $this->lastMessageText($thread, 'assistant'),
            lastUserText: $this->lastMessageText($thread, 'user'),
            newCommits: $this->newCommits($commitTimes, $startedAt),
            prUrl: $pullRequest['url'],
            ciSummary: $pullRequest['checks'],
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function threadPayload(array $snapshot): array
    {
        $thread = $snapshot['thread'] ?? $snapshot;

        if (! is_array($thread)) {
            return [];
        }

        /** @var array<string, mixed> $thread */
        return $thread;
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function sessionState(array $thread): ?string
    {
        $session = $thread['session'] ?? null;

        if (! is_array($session)) {
            return null;
        }

        return $this->string($session['status'] ?? null);
    }

    /**
     * A thread is idle when nothing waits on a human and its last turn ended.
     *
     * @param  array<string, mixed>  $thread
     * @param  array{approval: string|null, user_input: string|null}  $pending
     */
    private function isIdle(array $thread, array $pending): bool
    {
        if ($pending['approval'] !== null || $pending['user_input'] !== null) {
            return false;
        }

        $turnState = $this->latestTurnState($thread);

        if ($turnState !== null) {
            return in_array($turnState, self::FinishedTurnStates, true);
        }

        return in_array($this->sessionState($thread), self::WaitingSessionStates, true);
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function latestTurnState(array $thread): ?string
    {
        $latest = $thread['latestTurn'] ?? null;

        if (is_array($latest)) {
            $state = $this->string($latest['state'] ?? null);

            if ($state !== null) {
                return $state;
            }
        }

        $turns = $thread['turns'] ?? null;

        if (! is_array($turns)) {
            return null;
        }

        $latestId = $this->string($thread['latestTurnId'] ?? null);
        $state = null;

        foreach ($turns as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $candidate = $this->string($turn['state'] ?? null);

            if ($candidate === null) {
                continue;
            }

            if ($latestId !== null && $this->string($turn['turnId'] ?? null) === $latestId) {
                return $candidate;
            }

            $state = $candidate;
        }

        return $state;
    }

    /**
     * Unresolved approval and user-input request ids, newest first.
     *
     * @param  array<string, mixed>  $thread
     * @return array{approval: string|null, user_input: string|null}
     */
    private function pendingRequestIds(array $thread): array
    {
        $approvals = [];
        $userInputs = [];

        foreach ($this->listedRequests($thread, 'pendingApprovals') as $id) {
            $approvals[$id] = true;
        }

        foreach ($this->listedRequests($thread, 'pendingUserInputs') as $id) {
            $userInputs[$id] = true;
        }

        foreach ($this->activities($thread) as $activity) {
            $kind = strtolower($this->string($activity['kind'] ?? null) ?? '');
            $isUserInput = str_contains($kind, 'user-input');

            if (! $isUserInput && ! str_contains($kind, 'approval')) {
                continue;
            }

            $payload = is_array($activity['payload'] ?? null) ? $activity['payload'] : [];
            $id = $this->string($payload['requestId'] ?? $activity['requestId'] ?? null);

            if ($id === null) {
                continue;
            }

            if (str_ends_with($kind, '.requested')) {
                if ($isUserInput) {
                    $userInputs[$id] = true;
                } else {
                    $approvals[$id] = true;
                }

                continue;
            }

            if ($isUserInput) {
                unset($userInputs[$id]);
            } else {
                unset($approvals[$id]);
            }
        }

        return [
            'approval' => $this->lastKey($approvals),
            'user_input' => $this->lastKey($userInputs),
        ];
    }

    /**
     * Unresolved request ids listed directly on the thread snapshot.
     *
     * @param  array<string, mixed>  $thread
     * @return list<string>
     */
    private function listedRequests(array $thread, string $key): array
    {
        $items = $thread[$key] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            $id = is_string($item) ? $this->string($item) : null;
            $status = null;

            if (is_array($item)) {
                $id = $this->string($item['requestId'] ?? $item['id'] ?? null);
                $status = $this->string($item['status'] ?? null);
            }

            if ($id === null || $status === 'resolved') {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, true>  $ids
     */
    private function lastKey(array $ids): ?string
    {
        $keys = array_keys($ids);

        return $keys === [] ? null : (string) $keys[count($keys) - 1];
    }

    /**
     * @param  array<string, mixed>  $thread
     * @return list<array<string, mixed>>
     */
    private function activities(array $thread): array
    {
        $activities = $thread['activities'] ?? null;

        if (! is_array($activities)) {
            return [];
        }

        $rows = [];

        foreach ($activities as $activity) {
            if (is_array($activity)) {
                /** @var array<string, mixed> $activity */
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
        $messages = $thread['messages'] ?? null;

        if (! is_array($messages)) {
            return null;
        }

        $text = null;

        foreach ($messages as $message) {
            if (! is_array($message) || $this->string($message['role'] ?? null) !== $role) {
                continue;
            }

            $text = $this->string($message['text'] ?? null) ?? $text;
        }

        return $text === null ? null : Str::limit($text, self::ExcerptLimit, '');
    }

    /**
     * The pull request T3 links to the thread and the checks state it reports.
     *
     * @param  array<string, mixed>  $thread
     * @return array{url: string|null, checks: string|null}
     */
    private function pullRequest(array $thread): array
    {
        $candidates = [];

        foreach (['linkedPullRequest', 'branchPullRequest'] as $key) {
            $candidate = $thread[$key] ?? null;

            if (is_array($candidate)) {
                /** @var array<string, mixed> $candidate */
                $candidates[] = $candidate;
            }
        }

        $listed = $thread['pullRequests'] ?? null;

        if (is_array($listed)) {
            foreach ($listed as $candidate) {
                if (is_array($candidate)) {
                    /** @var array<string, mixed> $candidate */
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $snapshot = is_array($candidate['snapshot'] ?? null) ? $candidate['snapshot'] : $candidate;
            $url = $this->string($candidate['url'] ?? null);
            $checks = $this->string($snapshot['checksState'] ?? null);

            if ($url !== null || $checks !== null) {
                return ['url' => $url, 'checks' => $checks];
            }
        }

        return ['url' => null, 'checks' => null];
    }

    /**
     * @return list<CarbonImmutable>|null
     */
    private function commitTimes(TaskGroup $group): ?array
    {
        $instance = $group->taskable;

        return $instance instanceof AppInstance ? $this->commits->commitTimes($instance) : null;
    }

    /**
     * @param  list<CarbonImmutable>|null  $commitTimes
     */
    private function newCommits(?array $commitTimes, ?CarbonInterface $startedAt): ?int
    {
        if ($commitTimes === null || ! $startedAt instanceof CarbonInterface) {
            return null;
        }

        $new = 0;

        foreach ($commitTimes as $committedAt) {
            if ($committedAt->greaterThanOrEqualTo($startedAt)) {
                $new++;
            }
        }

        return $new;
    }

    private function currentTask(TaskGroup $group): ?Task
    {
        return $group->tasks->first(static fn (Task $task): bool => in_array(
            $task->status,
            [TaskStatus::Running, TaskStatus::Reviewing],
            true,
        ));
    }

    private function task(TaskGroup $group, ?int $taskId, string $threadId): ?Task
    {
        return $group->tasks->first(static fn (Task $task): bool => ($taskId !== null && $task->id === $taskId)
            || $task->implementer_thread_id === $threadId);
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
