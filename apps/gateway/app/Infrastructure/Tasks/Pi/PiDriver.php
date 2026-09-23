<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\AgentThreadState;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs agent threads on the Node's Pi server (ADR 0116). Orbit chooses the session ID, so the
 * external ID is known before the server answers. Each send carries a key; a retry reuses it,
 * so an ambiguous failure never starts a second turn.
 */
final readonly class PiDriver implements AgentDriver
{
    public function __construct(
        private PiClient $client,
        private PiConnection $connection,
        private PiNodeEligibility $nodes,
    ) {}

    public function key(): string
    {
        return 'pi';
    }

    public function allows(Node $node): bool
    {
        return $this->nodes->allows($node);
    }

    public function create(AgentThreadStart $intent): string
    {
        $cwd = $intent->workspace->checkout_path;
        if ($cwd === '') {
            throw new AgentDriverException('The workspace has no checkout path.');
        }
        $id = (string) Str::uuid();
        $this->client->create($intent->node, [
            'id' => $id,
            'cwd' => $cwd,
            'model' => PiModel::forModel($intent->model, $this->configuredProvider()),
            'thinkingLevel' => $intent->effort,
            'appendSystemPrompt' => null,
        ]);
        $this->deliver($intent->node, $id, $intent->prompt);

        return $id;
    }

    public function send(AgentThread $thread, string $message): void
    {
        $this->deliver($this->node($thread), $thread->external_id, $message);
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        throw AgentDriverException::unsupported('input requests on Pi threads');
    }

    public function interrupt(AgentThread $thread): void
    {
        $this->client->interrupt($this->node($thread), $thread->external_id);
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        $node = $this->node($thread);
        $snapshot = $this->client->snapshot($node, $thread->external_id);
        if (data_get($snapshot, 'session.id') !== $thread->external_id) {
            throw new AgentDriverException('Agent observation identity does not match.');
        }

        return $this->snapshotObservation($snapshot, new PiTranscript, $node);
    }

    /**
     * Relays one Pi server stream. A cursor is `{run}.{sequence}`. A cursor from the server's
     * current run resumes after it, so a reconnect gets only what it missed. Any other cursor,
     * including one from before a server restart, gets a fresh snapshot.
     */
    public function events(AgentThread $thread, ?string $cursor): iterable
    {
        $node = $this->node($thread);
        $transcript = new PiTranscript;
        $started = false;

        foreach ($this->client->stream($node, $thread->external_id, $this->resumeFrom($cursor)) as $event) {
            $kind = $event['kind'];
            $next = $this->cursor($event);
            if ($kind === 'heartbeat') {
                yield new AgentThreadEvent($thread->id, 'heartbeat');
            } elseif ($kind === 'snapshot') {
                if (data_get($event, 'session.id') !== $thread->external_id) {
                    continue;
                }
                $started = true;
                $transcript = new PiTranscript;
                yield new AgentThreadEvent($thread->id, 'snapshot', $this->snapshotObservation($event, $transcript, $node)->toArray(), $next);
            } elseif ($kind === 'resumed') {
                if (data_get($event, 'session.id') !== $thread->external_id) {
                    continue;
                }
                $started = true;
                $transcript = new PiTranscript;
                foreach (is_array($event['context'] ?? null) ? $event['context'] : [] as $entry) {
                    if (is_array($entry)) {
                        $transcript->remember($entry);
                    }
                }
                yield new AgentThreadEvent($thread->id, 'resumed');
            } elseif ($started && $kind === 'entry' && is_array($event['entry'] ?? null)) {
                yield from $this->entryEvents($thread, $this->redact($transcript->entries($event['entry']), $node), $next);
            } elseif ($started && $kind === 'state') {
                $state = $this->observation($event, [])->toArray();
                unset($state['entries']);
                if ($this->settled($state['state'])) {
                    yield from $this->entryEvents($thread, $this->redact($transcript->settle(), $node), null);
                }
                yield new AgentThreadEvent($thread->id, 'state', $this->redact($state, $node), $next);
            }
        }
    }

    /**
     * One Pi event can become several entries. Only the last carries the cursor, so a viewer that
     * disconnects between them resumes before the event and receives all of them again.
     *
     * @param  list<array{id: string, kind: string, label: string, text: string, at: string}>  $entries
     * @return iterable<AgentThreadEvent>
     */
    private function entryEvents(AgentThread $thread, array $entries, ?string $cursor): iterable
    {
        foreach ($entries as $index => $entry) {
            yield new AgentThreadEvent($thread->id, 'entry', ['entry' => $entry], $index === array_key_last($entries) ? $cursor : null);
        }
    }

    /** A settled turn has no running tool calls left. */
    private function settled(mixed $state): bool
    {
        return in_array(AgentThreadState::tryFrom(is_string($state) ? $state : ''), [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::Failed], true);
    }

    /** @return array{run: string, sequence: int}|null */
    private function resumeFrom(?string $cursor): ?array
    {
        if ($cursor === null || preg_match('/^([A-Za-z0-9_-]{1,64})\.(\d{1,15})$/', $cursor, $match) !== 1) {
            return null;
        }

        return ['run' => $match[1], 'sequence' => (int) $match[2]];
    }

    /** @param array<string, mixed> $event */
    private function cursor(array $event): ?string
    {
        $run = $event['run'] ?? null;
        $sequence = $event['sequence'] ?? null;

        return is_string($run) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $run) === 1 && is_int($sequence) && $sequence >= 0
            ? $run.'.'.$sequence
            : null;
    }

    private function deliver(Node $node, string $sessionId, string $message): void
    {
        $key = (string) Str::uuid();
        try {
            $this->client->send($node, $sessionId, $key, $message);
        } catch (AgentDriverException) {
            try {
                $this->client->send($node, $sessionId, $key, $message);
            } catch (AgentDriverException $exception) {
                Log::error('Pi server refused a turn after one retry.', ['session_id' => $sessionId, 'exception' => $exception->getMessage()]);

                throw $exception;
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotObservation(array $snapshot, PiTranscript $transcript, Node $node): AgentObservation
    {
        // A tool result replaces its running call in place, so entries are keyed by ID.
        $entries = [];
        foreach (is_array($snapshot['entries'] ?? null) ? $snapshot['entries'] : [] as $entry) {
            if (is_array($entry)) {
                foreach ($transcript->entries($entry) as $normalized) {
                    $entries[$normalized['id']] = $normalized;
                }
            }
        }
        if ($this->settled($snapshot['state'] ?? null)) {
            foreach ($transcript->settle() as $normalized) {
                $entries[$normalized['id']] = $normalized;
            }
        }

        return $this->observation($snapshot, $this->redact(array_values($entries), $node));
    }

    /**
     * @param  array<string, mixed>  $data  A snapshot or state event.
     * @param  list<array{id: string, kind: string, label: string, text: string, at: string}>  $entries
     */
    private function observation(array $data, array $entries): AgentObservation
    {
        $state = AgentThreadState::tryFrom(is_string($data['state'] ?? null) ? $data['state'] : '');
        $error = is_string($data['error'] ?? null) && $data['error'] !== '' ? $data['error'] : null;
        $tokens = data_get($data, 'usage.total');
        $turnId = $data['turnId'] ?? null;

        return new AgentObservation(
            state: $state,
            entries: $entries,
            tokens: is_int($tokens) ? $tokens : null,
            error: $state === AgentThreadState::Failed ? ($error ?? 'Agent turn failed.') : null,
            cursor: $this->cursor($data),
            turnId: is_string($turnId) && $turnId !== '' ? $turnId : null,
        );
    }

    /**
     * @template T of array
     *
     * @param  T  $data
     * @return T
     */
    private function redact(array $data, Node $node): array
    {
        $token = $this->connection->token($node);
        array_walk_recursive($data, static function (mixed &$value) use ($token): void {
            if (is_string($value)) {
                $value = str_replace($token, '[REDACTED]', $value);
            }
        });

        /** @var T */
        return new CommandActivityInputSanitizer()->sanitizeProperties($data);
    }

    private function configuredProvider(): ?string
    {
        $provider = config('orbit.pi.provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
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
