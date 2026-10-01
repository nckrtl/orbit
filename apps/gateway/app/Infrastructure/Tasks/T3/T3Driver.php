<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentMetricCollector;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\AgentThreadStart;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Node;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

final readonly class T3Driver implements AgentDriver, AgentMetricCollector
{
    public function __construct(
        private T3Dispatcher $dispatcher,
        private T3ThreadReader $reader,
        private T3ThreadCreator $creator,
        private T3Stream $stream,
        private T3Projection $projection,
        private T3NodeEligibility $nodes,
        private T3SendLeaseManager $sendLeases,
    ) {}

    public function key(): string
    {
        return 't3';
    }

    public function allows(Node $node): bool
    {
        return $this->nodes->allows($node);
    }

    public function create(AgentThreadStart $intent): string
    {
        return $this->creator->create($intent);
    }

    public function send(AgentThread $thread, string $message, ?string $key = null): void
    {
        $leaseToken = $this->sendLeases->acquire($thread);
        try {
            $thread->refresh();
            // A reserved resume key is the command id and the message id. T3 0.0.42 returns the
            // existing receipt for that command id, so a retry does not start a second turn (ADR 0167).
            $this->creator->startTurn($this->node($thread), $thread->external_id, $message, T3ModelSelection::forModel($thread->model ?? '', $thread->effort ?? Config::string($thread->role === 'reviewer' ? 'orbit.tasks.reviewer_effort' : 'orbit.tasks.implementer_effort')), $key);
        } finally {
            $this->sendLeases->release($thread, $leaseToken);
            $thread->refresh();
        }
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        $response = match ($request->kind) {
            'approval' => ['type' => 'thread.approval.respond', 'decision' => ($answers['approve'] ?? false) === true ? 'acceptForSession' : 'decline'],
            'question' => ['type' => 'thread.user-input.respond', 'answers' => $answers],
            default => throw AgentDriverException::unsupported('this input request'),
        };
        $this->dispatcher->dispatch($this->node($thread), [
            ...$response, 'threadId' => $thread->external_id, 'requestId' => $request->id,
            'commandId' => (string) Str::uuid(), 'createdAt' => now()->toIso8601String(),
        ]);
    }

    public function interrupt(AgentThread $thread): void
    {
        $this->dispatcher->dispatch($this->node($thread), [
            'type' => 'thread.turn.interrupt', 'threadId' => $thread->external_id,
            'commandId' => (string) Str::uuid(), 'createdAt' => now()->toIso8601String(),
        ]);
    }

    public function archive(AgentThread $thread, string $commandId): void
    {
        $this->dispatcher->dispatch($this->node($thread), [
            'type' => 'thread.archive', 'threadId' => $thread->external_id,
            'commandId' => $commandId, 'createdAt' => now()->toIso8601String(),
        ]);
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        $snapshot = $this->reader->snapshot($this->node($thread), $thread->external_id);
        if ($snapshot === null) {
            throw new AgentDriverException('Agent observation unavailable.');
        }
        $id = data_get($snapshot, 'thread.id');
        if ($id !== null && $id !== $thread->external_id) {
            throw new AgentDriverException('Agent observation identity does not match.');
        }

        return $this->projection->observe(
            $this->redact($snapshot, $this->node($thread)),
            $thread->state,
            $thread->error,
            checkpoint: $this->metricsCheckpoint($thread),
            accumulateMetrics: false,
        );
    }

    public function collectMetrics(AgentThread $thread): bool
    {
        $cursor = $thread->t3_event_sequence === null ? null : (string) $thread->t3_event_sequence;
        $caughtUp = false;
        foreach ($this->events($thread, $cursor, 1.0) as $event) {
            // Heartbeats only mean the stream stayed open; they do not prove a snapshot was read.
            $caughtUp = $caughtUp || $event->kind !== 'heartbeat';
        }

        return $caughtUp;
    }

    public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable
    {
        if ($cursor !== null && (! ctype_digit($cursor) || strlen($cursor) > 16 || (int) $cursor > 9007199254740991)) {
            throw new AgentDriverException('Invalid agent stream cursor.');
        }
        $node = $this->node($thread);
        $raw = [];
        $messages = [];
        $metadata = [];
        $sequence = -1;
        $storedSequence = $thread->t3_event_sequence;
        $requestedSequence = $cursor === null ? null : (int) $cursor;
        // T3's viewer contract is a full snapshot on every connection. Only
        // the scheduled collector resumes from the durable metric cursor;
        // Last-Event-ID is used by the browser's SSE layer, not upstream T3.
        $afterSequence = $timeoutSeconds !== null
            ? max($storedSequence ?? -1, $requestedSequence ?? -1)
            : -1;
        $previous = $thread->state;
        $previousError = $thread->error;
        // Each connection starts with a baseline; cursors never imply a delta-only subscription.
        foreach ($this->stream->events($node, $thread->external_id, $afterSequence >= 0 ? $afterSequence : null, $timeoutSeconds) as $item) {
            $kind = $item['kind'] ?? null;
            if ($kind === 'heartbeat') {
                yield new AgentThreadEvent($thread->id, 'heartbeat');

                continue;
            }
            if ($kind === 'snapshot') {
                $snapshot = $item['snapshot'] ?? null;
                if (! is_array($snapshot) || data_get($snapshot, 'thread.id') !== $thread->external_id) {
                    continue;
                }
                $threadData = $snapshot['thread'] ?? null;
                if (! is_array($threadData)) {
                    continue;
                }
                $raw = array_filter($threadData, is_string(...), ARRAY_FILTER_USE_KEY);
                $sequence = is_int($snapshot['snapshotSequence'] ?? null) ? $snapshot['snapshotSequence'] : -1;
                $metrics = $this->persistBaseline($thread, $this->redact($snapshot, $node), $sequence >= 0 ? $sequence : null);
                $observed = $this->projection->observe(
                    $this->redact($snapshot, $node),
                    $previous,
                    $previousError,
                    checkpoint: $metrics->checkpoint,
                    accumulateMetrics: false,
                );
                $previous = $observed->state;
                $previousError = $observed->error;
                $metadata = $observed->toArray();
                unset($metadata['entries']);
                $messages = [];
                $rawMessages = $raw['messages'] ?? null;
                foreach (is_array($rawMessages) ? $rawMessages : [] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    $message = array_filter($message, is_string(...), ARRAY_FILTER_USE_KEY);
                    $messageId = $message['id'] ?? $message['messageId'] ?? null;
                    if (is_string($messageId)) {
                        $messages[$messageId] = $message;
                    }
                }
                $raw = $this->streamMetadata($raw, $observed);
                yield new AgentThreadEvent($thread->id, 'snapshot', $observed->toArray(), $sequence >= 0 ? (string) $sequence : null);

                continue;
            }
            $event = $item['event'] ?? null;
            if ($kind !== 'event' || ! is_array($event) || ($event['aggregateId'] ?? null) !== $thread->external_id || ! is_int($event['sequence'] ?? null) || $event['sequence'] <= $sequence) {
                continue;
            }
            $sequence = $event['sequence'];
            $metrics = $this->persistEvent($thread, $event, $sequence);
            $type = $event['type'] ?? null;
            if ($raw === []) {
                continue;
            }
            if ($type === 'thread.message-sent') {
                $id = data_get($event, 'payload.messageId') ?? data_get($event, 'payload.id');
                if (! is_string($id) || $id === '') {
                    continue;
                }
                $single = $this->projection->apply(['messages' => isset($messages[$id]) ? [$messages[$id]] : []], $event);
                $singleMessages = $single['messages'] ?? null;
                $singleMessage = is_array($singleMessages) ? ($singleMessages[0] ?? null) : null;
                if (! is_array($singleMessage)) {
                    continue;
                }
                $messages[$id] = array_filter($singleMessage, is_string(...), ARRAY_FILTER_USE_KEY);
                $entry = $this->projection->observe($this->redact($single, $node))->entries[0] ?? null;
                if ($entry !== null) {
                    yield new AgentThreadEvent($thread->id, 'entry', ['entry' => $entry], (string) $sequence);
                }

                continue;
            }
            if (! in_array($type, ['thread.activity-appended', 'thread.session-set', 'thread.turn-start-requested', 'thread.turn-diff-completed'], true)) {
                continue;
            }
            $raw = $this->projection->apply($raw, $event);
            $observed = $this->projection->observe(
                $this->redact(['thread' => $raw], $node),
                $previous,
                $previousError,
                includeEntries: false,
                checkpoint: $metrics->checkpoint,
                accumulateMetrics: false,
            );
            $raw = $this->streamMetadata($raw, $observed);
            $previous = $observed->state;
            $previousError = $observed->error;
            $next = $observed->toArray();
            unset($next['entries']);
            if ($type === 'thread.activity-appended') {
                $single = $this->projection->apply([], $event);
                $entry = $this->projection->observe($this->redact($single, $node))->entries[0] ?? null;
                if ($entry !== null) {
                    yield new AgentThreadEvent($thread->id, 'entry', [...$next, 'entry' => $entry], (string) $sequence);
                }
            } elseif ($next !== $metadata) {
                yield new AgentThreadEvent($thread->id, 'state', $next, (string) $sequence);
            }
            $metadata = $next;
        }
    }

    /**
     * Keep only state, checkpoints, unresolved requests, and cumulative usage between events.
     * Transcript entries are emitted once and message fragments are indexed separately.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function streamMetadata(array $raw, AgentObservation $observed): array
    {
        $pending = ['approval' => [], 'question' => []];
        foreach ($observed->inputRequests as $request) {
            $pending[$request->kind][] = [...$request->details, 'requestId' => $request->id];
        }

        return [
            ...array_intersect_key($raw, array_flip(['id', 'session', 'sess', 'latestTurn', 'latest_turn', 'error', 'checkpoints'])),
            'pendingApprovals' => $pending['approval'], 'pendingUserInputs' => $pending['question'],
            'usage' => ['totalProcessedTokens' => $observed->tokens, 'usedTokens' => $observed->tokens],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redact(array $data, Node $node): array
    {
        $token = new T3Connection()->credentials($node)['token'];
        if ($token !== null) {
            array_walk_recursive($data, static function (mixed &$value) use ($token): void {
                if (is_string($value)) {
                    $value = str_replace($token, '[REDACTED]', $value);
                }
            });
        }

        return new CommandActivityInputSanitizer()->sanitizeProperties($data);
    }

    /** @param array<string, mixed> $snapshot */
    private function persistBaseline(AgentThread $thread, array $snapshot, ?int $sequence): T3ThreadMetrics
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($sequence !== null && ($thread->t3_event_sequence ?? -1) >= $sequence) {
                return T3ThreadMetrics::fromPersisted($snapshot, $this->metricsCheckpoint($thread));
            }

            $metrics = T3ThreadMetrics::baseline($snapshot, $this->metricsCheckpoint($thread), $sequence);
            if ($this->persistMetrics($thread, $metrics)) {
                return $metrics;
            }
        }

        throw new AgentDriverException('The T3 metric checkpoint could not be saved.');
    }

    /** @param array<string, mixed> $event */
    private function persistEvent(AgentThread $thread, array $event, int $sequence): T3ThreadMetrics
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (($thread->t3_event_sequence ?? -1) >= $sequence) {
                return T3ThreadMetrics::fromPersisted([], $this->metricsCheckpoint($thread));
            }

            $metrics = T3ThreadMetrics::fromEvent($event, $this->metricsCheckpoint($thread), $sequence);
            if ($this->persistMetrics($thread, $metrics)) {
                return $metrics;
            }
        }

        throw new AgentDriverException('The T3 metric checkpoint could not be saved.');
    }

    private function persistMetrics(AgentThread $thread, T3ThreadMetrics $metrics): bool
    {
        $checkpoint = $metrics->checkpoint;
        if ($checkpoint === null) {
            return true;
        }

        $values = [
            ...$checkpoint,
            'input_tokens' => $metrics->inputTokens,
            'cached_input_tokens' => $metrics->cachedInputTokens,
            'output_tokens' => $metrics->outputTokens,
            'model_calls' => $metrics->modelCalls,
            'peak_context_tokens' => $metrics->peakContextTokens,
        ];
        if ($metrics->tokens !== null) {
            $values['tokens'] = max($thread->tokens ?? 0, $metrics->tokens);
        }

        $query = AgentThread::query()->whereKey($thread->id);
        $sequence = $checkpoint['t3_event_sequence'] ?? null;
        if (is_int($sequence) && is_int($thread->t3_event_sequence) && $thread->t3_event_sequence >= $sequence) {
            return true;
        }
        if (is_int($sequence)) {
            $query->where(static function ($query) use ($sequence): void {
                $query->whereNull('t3_event_sequence')->orWhere('t3_event_sequence', '<', $sequence);
            });
        }
        $updated = $query->update($values);
        $thread->refresh();

        return $updated === 1;
    }

    /** @return array<string, mixed>|null */
    private function metricsCheckpoint(AgentThread $thread): ?array
    {
        if ($thread->t3_metrics_initialized !== true) {
            return null;
        }

        return [
            't3_input_tokens' => $thread->t3_input_tokens,
            't3_cached_input_tokens' => $thread->t3_cached_input_tokens,
            't3_output_tokens' => $thread->t3_output_tokens,
            't3_model_calls' => $thread->t3_model_calls,
            't3_peak_context_tokens' => $thread->t3_peak_context_tokens,
            't3_counted_total_processed_tokens' => $thread->t3_counted_total_processed_tokens,
            't3_observed_total_processed_tokens' => $thread->t3_observed_total_processed_tokens,
            't3_event_sequence' => $thread->t3_event_sequence,
            't3_metrics_partial' => $thread->t3_metrics_partial,
            't3_metrics_initialized' => true,
        ];
    }

    private function node(AgentThread $thread): Node
    {
        $node = $thread->node;
        if ($node === null || $node->status !== LifecycleStatus::Active || $thread->runtime_key !== 'node:'.$node->id) {
            throw new AgentDriverException('The original agent Node is unavailable.');
        }

        return $node;
    }
}
