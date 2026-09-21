<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;

final readonly class T3Projection
{
    /** @param array<string, mixed> $snapshot */
    public function observe(array $snapshot, ?AgentThreadState $previous = null, ?string $previousError = null, bool $includeEntries = true): AgentObservation
    {
        $thread = $this->map($snapshot['thread'] ?? $snapshot);
        $session = $this->map($thread['session'] ?? $thread['sess'] ?? []);
        $turn = $this->map($thread['latestTurn'] ?? $thread['latest_turn'] ?? []);
        $status = $this->text($session['status'] ?? $session['state'] ?? '');
        $turnStatus = $this->text($turn['state'] ?? $turn['status'] ?? '');
        $state = $this->state($status, $turnStatus);
        $retained = $state === AgentThreadState::Idle && $previous === AgentThreadState::Failed;
        if ($state === AgentThreadState::Idle && in_array($previous, [AgentThreadState::Done, AgentThreadState::Failed], true)) {
            $state = $previous;
        }
        $requests = in_array($state, [AgentThreadState::Working, AgentThreadState::Done, AgentThreadState::Failed], true)
            ? [] : $this->requests($thread, $turn);
        if ($requests !== []) {
            $state = AgentThreadState::AskingForInput;
        }
        $entries = [];
        foreach ($includeEntries ? ['messages' => 'message', 'activities' => 'activity'] : [] as $key => $kind) {
            foreach ($this->rows($thread[$key] ?? []) as $row) {
                $payload = $this->map($row['payload'] ?? []);
                $id = $this->text($row['id'] ?? $row['messageId'] ?? '');
                $text = $this->text($row['text'] ?? $row['summary'] ?? $payload['text'] ?? '');
                if ($id === '') {
                    $id = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                }
                $entries[] = [
                    'id' => $id, 'kind' => $kind,
                    'label' => $this->text($row['role'] ?? $row['kind'] ?? $payload['role'] ?? 'Activity'),
                    'text' => $text, 'at' => $this->text($row['createdAt'] ?? ''),
                ];
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));
        $metrics = T3ThreadMetrics::fromSnapshot($snapshot);
        $error = $thread['error'] ?? $turn['error'] ?? $session['lastError'] ?? $session['error'] ?? null;
        $error = is_array($error) ? ($error['message'] ?? null) : $error;
        $cursor = $snapshot['snapshotSequence'] ?? $snapshot['sequence'] ?? null;

        return new AgentObservation(
            state: $state, inputRequests: $requests, entries: $entries,
            tokens: $metrics->tokens, linesAdded: $metrics->linesAdded, linesDeleted: $metrics->linesDeleted,
            error: $state === AgentThreadState::Failed ? ($this->text($error) ?: ($retained ? $previousError : null) ?? 'Agent turn failed.') : null,
            cursor: is_int($cursor) ? (string) $cursor : null,
        );
    }

    private function state(string $status, string $turn): ?AgentThreadState
    {
        if (in_array($status, ['error', 'failed'], true)) {
            return AgentThreadState::Failed;
        }
        if (in_array($status, ['running', 'working', 'starting'], true)) {
            return AgentThreadState::Working;
        }
        if (in_array($turn, ['error', 'failed'], true)) {
            return AgentThreadState::Failed;
        }
        if (in_array($status, ['done', 'completed'], true) || in_array($turn, ['done', 'completed'], true)) {
            return AgentThreadState::Done;
        }
        if (in_array($turn, ['running', 'working', 'starting'], true)) {
            return AgentThreadState::Working;
        }
        if (in_array($status, ['waiting', 'asking_for_input', 'pending_input', 'waiting_for_input'], true)) {
            return AgentThreadState::AskingForInput;
        }

        return in_array($status, ['idle', 'ready', 'stopped', 'interrupted'], true) ? AgentThreadState::Idle : null;
    }

    /** @param array<string, mixed> $thread
     * @param  array<string, mixed>  $turn
     * @return list<AgentInputRequest>
     */
    private function requests(array $thread, array $turn): array
    {
        $pending = [];
        $turnId = $turn['id'] ?? $turn['turnId'] ?? null;
        foreach (['approval' => ['pendingApprovals', 'pending_approvals'], 'question' => ['pendingUserInputs', 'pending_user_inputs']] as $kind => $keys) {
            foreach ($keys as $key) {
                foreach (is_array($thread[$key] ?? null) ? $thread[$key] : [] as $item) {
                    $details = is_array($item) ? $item : [];
                    $id = is_string($item) ? $item : $this->text($details['requestId'] ?? $details['request_id'] ?? $details['id'] ?? '');
                    if ($id !== '' && (! isset($details['turnId']) || $turnId === null || $details['turnId'] === $turnId)) {
                        $pending[$id] = new AgentInputRequest($id, $kind, $details);
                    }
                }
            }
        }
        foreach ($this->rows($thread['activities'] ?? []) as $activity) {
            $kind = strtolower($this->text($activity['kind'] ?? $activity['tone'] ?? ''));
            $approval = str_contains($kind, 'approval') || ($activity['tone'] ?? null) === 'approval';
            if (! $approval && ! str_contains($kind, 'user-input') && ! str_contains($kind, 'user_input')) {
                continue;
            }
            $payload = $this->map($activity['payload'] ?? []);
            $id = $this->text($payload['requestId'] ?? $payload['request_id'] ?? $activity['requestId'] ?? $activity['request_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $requestTurn = $payload['turnId'] ?? $activity['turnId'] ?? null;
            $started = $this->text($turn['startedAt'] ?? '');
            $created = $this->text($activity['createdAt'] ?? '');
            if (($turnId !== null && $requestTurn !== null && $turnId !== $requestTurn) || ($started !== '' && $created !== '' && strcmp($created, $started) < 0)) {
                continue;
            }
            if (str_contains($kind, 'respond') || str_contains($kind, 'resolved') || str_contains($kind, 'cancel')) {
                unset($pending[$id]);
            } else {
                $pending[$id] = new AgentInputRequest($id, $approval ? 'approval' : 'question', $payload);
            }
        }

        return array_values($pending);
    }

    /** @param array<string, mixed> $thread
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function apply(array $thread, array $event): array
    {
        $payload = $this->map($event['payload'] ?? []);
        switch ($event['type'] ?? '') {
            case 'thread.message-sent':
                $messages = $this->rows($thread['messages'] ?? []);
                foreach ($messages as $message) {
                    if (($message['id'] ?? $message['messageId'] ?? null) === ($payload['messageId'] ?? $payload['id'] ?? null)) {
                        $text = $this->text($payload['text'] ?? '');
                        $payload['text'] = ($payload['streaming'] ?? false) === true
                            ? $this->text($message['text'] ?? '').$text
                            : ($text !== '' ? $text : $this->text($message['text'] ?? ''));
                        $payload = [...$message, ...$payload];
                        break;
                    }
                }
                $thread['messages'] = $this->upsert($messages, $payload);
                break;
            case 'thread.activity-appended':
                $thread['activities'] = $this->upsert($this->rows($thread['activities'] ?? []), $this->map($payload['activity'] ?? $payload));
                break;
            case 'thread.session-set':
                $session = $this->map($payload['session'] ?? []);
                $turn = $this->map($thread['latestTurn'] ?? []);
                $status = $session['status'] ?? null;
                if ($status === 'running' && isset($session['activeTurnId'])) {
                    $same = ($turn['turnId'] ?? null) === $session['activeTurnId'];
                    $thread['latestTurn'] = [
                        'turnId' => $session['activeTurnId'], 'state' => 'running',
                        'startedAt' => $same ? ($turn['startedAt'] ?? $session['updatedAt'] ?? null) : ($session['updatedAt'] ?? null),
                    ];
                } elseif (($turn['state'] ?? null) === 'running') {
                    $settled = match ($status) {
                        'idle', 'ready' => 'completed',
                        'error' => 'error',
                        'interrupted', 'stopped' => 'interrupted',
                        default => null,
                    };
                    if ($settled !== null) {
                        $thread['latestTurn'] = [...$turn, 'state' => $settled, 'completedAt' => $session['updatedAt'] ?? null];
                    }
                }
                $thread['session'] = $session;
                break;
            case 'thread.turn-start-requested':
                $thread['error'] = null;
                $thread['session'] = ['status' => 'starting'];
                $thread['latestTurn'] = ['state' => 'running', 'startedAt' => $event['occurredAt'] ?? $payload['createdAt'] ?? now()->toIso8601String()];
                $thread['pendingApprovals'] = $thread['pendingUserInputs'] = [];
                break;
            case 'thread.turn-diff-completed':
                $checkpoints = $this->rows($thread['checkpoints'] ?? []);
                foreach ($checkpoints as $checkpoint) {
                    if (($checkpoint['turnId'] ?? null) === ($payload['turnId'] ?? null) && ($checkpoint['status'] ?? null) !== 'missing' && ($payload['status'] ?? null) === 'missing') {
                        return $thread;
                    }
                }
                $thread['checkpoints'] = [...array_values(array_filter($checkpoints, static fn (array $checkpoint): bool => ($checkpoint['turnId'] ?? null) !== ($payload['turnId'] ?? null))), $payload];
                $session = $this->map($thread['session'] ?? []);
                if (($session['status'] ?? null) !== 'running' || ($session['activeTurnId'] ?? null) !== ($payload['turnId'] ?? null)) {
                    $turn = $this->map($thread['latestTurn'] ?? []);
                    $interrupted = ($turn['turnId'] ?? null) === ($payload['turnId'] ?? null) && ($turn['state'] ?? null) === 'interrupted';
                    $thread['latestTurn'] = [
                        ...$turn, 'turnId' => $payload['turnId'] ?? null,
                        'state' => $interrupted ? 'interrupted' : (($payload['status'] ?? null) === 'error' ? 'error' : 'completed'),
                        'completedAt' => $payload['completedAt'] ?? null,
                    ];
                }
                break;
        }
        if (isset($payload['latestTurn'])) {
            $thread['latestTurn'] = $payload['latestTurn'];
        }
        if (isset($payload['checkpoints'])) {
            $thread['checkpoints'] = $payload['checkpoints'];
        }

        return $thread;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function upsert(array $rows, array $value): array
    {
        $id = $value['id'] ?? $value['messageId'] ?? null;
        foreach ($rows as $index => $row) {
            if ($id !== null && ($row['id'] ?? $row['messageId'] ?? null) === $id) {
                $rows[$index] = $value;

                return $rows;
            }
        }
        $rows[] = $value;

        return $rows;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
