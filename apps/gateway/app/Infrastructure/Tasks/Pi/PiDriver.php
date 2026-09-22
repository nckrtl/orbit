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

    public function events(AgentThread $thread, ?string $cursor): iterable
    {
        if ($cursor !== null && (! ctype_digit($cursor) || strlen($cursor) > 16)) {
            throw new AgentDriverException('Invalid agent stream cursor.');
        }
        $node = $this->node($thread);
        $transcript = new PiTranscript;
        $started = false;

        // Every connection starts with a snapshot, so a reconnect never needs the old cursor.
        foreach ($this->client->stream($node, $thread->external_id) as $event) {
            $kind = $event['kind'];
            $sequence = is_int($event['sequence'] ?? null) ? (string) $event['sequence'] : null;
            if ($kind === 'heartbeat') {
                yield new AgentThreadEvent($thread->id, 'heartbeat');
            } elseif ($kind === 'snapshot') {
                if (data_get($event, 'session.id') !== $thread->external_id) {
                    continue;
                }
                $started = true;
                $transcript = new PiTranscript;
                yield new AgentThreadEvent($thread->id, 'snapshot', $this->snapshotObservation($event, $transcript, $node)->toArray(), $sequence);
            } elseif ($started && $kind === 'entry' && is_array($event['entry'] ?? null)) {
                /** @var array<string, mixed> $entry */
                $entry = $event['entry'];
                foreach ($this->redact($transcript->entries($entry), $node) as $normalized) {
                    yield new AgentThreadEvent($thread->id, 'entry', ['entry' => $normalized], $sequence);
                }
            } elseif ($started && $kind === 'state') {
                $state = $this->observation($event, [])->toArray();
                unset($state['entries']);
                yield new AgentThreadEvent($thread->id, 'state', $this->redact($state, $node), $sequence);
            }
        }
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
        $entries = [];
        foreach (is_array($snapshot['entries'] ?? null) ? $snapshot['entries'] : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                array_push($entries, ...$transcript->entries($entry));
            }
        }

        return $this->observation($snapshot, $this->redact($entries, $node));
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
        $sequence = $data['sequence'] ?? null;
        $turnId = $data['turnId'] ?? null;

        return new AgentObservation(
            state: $state,
            entries: $entries,
            tokens: is_int($tokens) ? $tokens : null,
            error: $state === AgentThreadState::Failed ? ($error ?? 'Agent turn failed.') : null,
            cursor: is_int($sequence) ? (string) $sequence : null,
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
