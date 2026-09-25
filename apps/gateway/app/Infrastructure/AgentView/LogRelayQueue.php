<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamEndReason;
use Closure;

/**
 * The live log events that wait for a relay run (ADR 0153). The agent view subscriber fills it
 * inside its socket loop, so it never reads the database or the cache and never calls Reverb. It
 * checks each event's shape, applies the Gateway's rate for each stream, and keeps the events in
 * arrival order. {@see ProcessAgentViewPublisher} hands them to one relay run at a time.
 *
 * No line is lost silently. Lines above the rate are counted in `dropped`. A failed run puts its
 * items back in front of the queue, and the relay skips the parts it already published. A stream
 * whose waiting lines pass `BacklogBytes`, or whose lines wait through `MaxAttempts` failed runs,
 * ends with `relay_behind`, and later events for it are ignored.
 *
 * Every `SweepSeconds` while streams may be open, a run also ends streams whose lease ran out or
 * whose viewer lost access. Every `PromptSeconds`, that sweep also prompts again each Node with an
 * active stream that has not relayed a line yet: an agent that reads no stream fetches its list only
 * when prompted, so a prompt lost while Reverb was unreachable would leave the stream silent.
 *
 * @phpstan-type Item array{type: 'lines', item: int, node: int, stream: string, lines: list<string>, dropped: int, skipped: int}|array{type: 'end', item: int, node: int, stream: string, reason: string}|array{type: 'agent_left', item: int, node: int}
 * @phpstan-type Batch array{relay: string, items: list<Item>, sweep: bool, prompt: bool}
 */
final class LogRelayQueue
{
    public const int RateBytesPerSecond = 65_536;

    public const int BurstBytes = 262_144;

    /** The most bytes of lines one stream may have waiting. */
    public const int BacklogBytes = 1_048_576;

    /** The most bytes of lines all streams together may have waiting. */
    public const int TotalBacklogBytes = 16_777_216;

    /** The bytes of lines one relay run takes, unless its first item alone is larger, so it ends well before its deadline. */
    public const int BatchBytes = 262_144;

    /** The longest line the relay accepts; the agent already cuts lines at 8 KiB. */
    public const int MaxLineBytes = 8_192 + 64;

    /** The most lines one agent event may carry. */
    public const int MaxLines = 2_000;

    public const int SweepSeconds = 5;

    public const int PromptSeconds = 15;

    /** Failed runs in a row after which the streams with waiting lines end with `relay_behind`. */
    public const int MaxAttempts = 5;

    /** Seconds the queue ignores the events of a stream it ended, longer than a lease. */
    public const int EndedSeconds = 120;

    private readonly string $relay;

    private int $nextItem = 1;

    /** @var list<Item> */
    private array $items = [];

    /** @var list<Item> The items of the running relay run. */
    private array $inFlight = [];

    /** @var array<string, array{tokens: float, at: float}> */
    private array $buckets = [];

    /** @var array<string, int> Bytes of lines waiting in the queue, by stream. */
    private array $queued = [];

    /** @var array<string, int> Bytes of lines in the running relay run, by stream. */
    private array $flying = [];

    /** @var array<string, float> Streams the queue ended, until when it ignores them. */
    private array $ended = [];

    private int $failures = 0;

    /** Items below this number went to a run once, so no later lines may join them. */
    private int $sealedBelow = 1;

    private float $nextSweepAt = 0.0;

    private float $nextPromptAt = 0.0;

    /** Whether a stream may be open: true after any log event, and until a run finds none. */
    private bool $streamsMayBeOpen = false;

    /** Whether a log event arrived while the current run was on its way. */
    private bool $eventDuringRun = false;

    /** @param Closure(): float $clock */
    public function __construct(private readonly Closure $clock, ?string $relay = null)
    {
        $this->relay = $relay ?? bin2hex(random_bytes(8));
    }

    /**
     * Queues one `client-log` event that Reverb stamped with `agent.{nodeId}`.
     *
     * @param  array<string, mixed>  $data
     */
    public function lines(int $nodeId, array $data): void
    {
        $stream = $this->streamId($data);
        $lines = $data['lines'] ?? null;
        $dropped = $data['dropped'] ?? 0;
        $skipped = $data['skipped'] ?? 0;

        if (
            $stream === null || ! is_array($lines) || ! array_is_list($lines) || count($lines) > self::MaxLines
            || ! array_all($lines, static fn (mixed $line): bool => is_string($line)) || ! is_int($dropped) || $dropped < 0 || ! is_int($skipped) || $skipped < 0
        ) {
            return;
        }

        $this->noteEvent();

        if ($this->isEnded($stream)) {
            return;
        }

        /** @var list<string> $lines */
        [$kept, $limited] = $this->limit($stream, array_map($this->line(...), $lines));
        $dropped += $limited;

        if ($kept === [] && $dropped === 0 && $skipped === 0) {
            return;
        }

        $bytes = self::bytes($kept);
        $this->queued[$stream] = ($this->queued[$stream] ?? 0) + $bytes;

        if (
            ($this->queued[$stream] + ($this->flying[$stream] ?? 0)) > self::BacklogBytes
            || array_sum($this->queued) + array_sum($this->flying) > self::TotalBacklogBytes
        ) {
            $this->endBehind($nodeId, [$stream]);

            return;
        }

        $last = array_key_last($this->items);
        $tail = $last === null ? null : $this->items[$last];

        // Lines that follow lines of the same stream join that item, so a catch-up needs fewer events.
        if ($tail !== null && $tail['type'] === 'lines' && $tail['stream'] === $stream && $tail['item'] >= $this->sealedBelow && self::bytes($tail['lines']) + $bytes <= self::BatchBytes) {
            $tail['lines'] = [...$tail['lines'], ...$kept];
            $tail['dropped'] += $dropped;
            $tail['skipped'] += $skipped;
            $this->items[$last] = $tail;

            return;
        }

        $this->items[] = ['type' => 'lines', 'item' => $this->nextItem++, 'node' => $nodeId, 'stream' => $stream, 'lines' => $kept, 'dropped' => $dropped, 'skipped' => $skipped];
    }

    /**
     * Queues the end of one stream after its agent reported `client-log-end`.
     *
     * @param  array<string, mixed>  $data
     */
    public function end(int $nodeId, array $data): void
    {
        $stream = $this->streamId($data);

        if ($stream === null) {
            return;
        }

        $this->noteEvent();

        if (! $this->isEnded($stream)) {
            $this->ended[$stream] = $this->now() + self::EndedSeconds;
            $this->items[] = ['type' => 'end', 'item' => $this->nextItem++, 'node' => $nodeId, 'stream' => $stream, 'reason' => LogStreamEndReason::SourceUnavailable->value];
        }
    }

    /** Queues the end of every stream of a Node whose agent left its log channel, after its waiting lines. */
    public function agentLeft(int $nodeId): void
    {
        $this->noteEvent();
        $this->items[] = ['type' => 'agent_left', 'item' => $this->nextItem++, 'node' => $nodeId];
    }

    /**
     * A stream may have opened: `log-streams.changed` passed on a Node log channel, or the subscriber
     * connected and cannot know which streams opened while it was away.
     */
    public function streamsChanged(): void
    {
        $this->noteEvent();
    }

    /**
     * The next relay run's work, or null when nothing is due. The items leave the queue until the
     * run {@see succeeded()} or {@see failed()}.
     *
     * @return Batch|null
     */
    public function take(): ?array
    {
        if ($this->inFlight !== []) {
            return null;
        }

        $now = $this->now();
        $this->ended = array_filter($this->ended, static fn (float $until): bool => $until > $now);
        $sweep = $this->streamsMayBeOpen && $now >= $this->nextSweepAt;

        if ($this->items === [] && ! $sweep) {
            return null;
        }

        $prompt = $sweep && $now >= $this->nextPromptAt;

        if ($sweep) {
            $this->nextSweepAt = $now + self::SweepSeconds;
        }

        if ($prompt) {
            $this->nextPromptAt = $now + self::PromptSeconds;
        }

        $this->sealedBelow = $this->nextItem;

        $bytes = 0;

        while ($this->items !== []) {
            $item = $this->items[0];
            $size = $item['type'] === 'lines' ? self::bytes($item['lines']) : 0;

            if ($this->inFlight !== [] && $bytes + $size > self::BatchBytes) {
                break;
            }

            array_shift($this->items);
            $this->inFlight[] = $item;

            if ($item['type'] === 'lines') {
                $bytes += $size;
                $this->queued[$item['stream']] = max(0, ($this->queued[$item['stream']] ?? 0) - $size);
                $this->flying[$item['stream']] = ($this->flying[$item['stream']] ?? 0) + $size;
            }
        }

        $this->eventDuringRun = false;

        return ['relay' => $this->relay, 'items' => $this->inFlight, 'sweep' => $sweep, 'prompt' => $prompt];
    }

    /** The run relayed its items. `$openStreams` is how many streams it found open, when it said. */
    public function succeeded(?int $openStreams): void
    {
        $this->inFlight = [];
        $this->flying = [];
        $this->failures = 0;
        $this->queued = array_filter($this->queued);

        if ($openStreams === 0 && $this->items === [] && ! $this->eventDuringRun) {
            $this->streamsMayBeOpen = false;
        }
    }

    /**
     * The run failed or was stopped: its items go back in front of the queue, and after `MaxAttempts`
     * failures in a row the streams with waiting lines end with `relay_behind`.
     */
    public function failed(): void
    {
        foreach ($this->inFlight as $item) {
            if ($item['type'] === 'lines') {
                $this->queued[$item['stream']] = ($this->queued[$item['stream']] ?? 0) + self::bytes($item['lines']);
            }
        }

        $this->items = [...$this->inFlight, ...$this->items];
        $this->inFlight = [];
        $this->flying = [];

        if (++$this->failures < self::MaxAttempts) {
            return;
        }

        $this->failures = 0;
        $behind = [];

        foreach ($this->items as $item) {
            if ($item['type'] === 'lines') {
                $behind[$item['node']][$item['stream']] = true;
            }
        }

        foreach ($behind as $nodeId => $streams) {
            $this->endBehind($nodeId, array_keys($streams));
        }
    }

    /** @return list<Item> The items waiting for a run. */
    public function pending(): array
    {
        return $this->items;
    }

    /** Whether a stream may be open, so the queue keeps asking for sweeps. */
    public function streamsMayBeOpen(): bool
    {
        return $this->streamsMayBeOpen;
    }

    /**
     * Drops the waiting lines of these streams and queues their end with `relay_behind`.
     *
     * @param  list<string>  $streams
     */
    private function endBehind(int $nodeId, array $streams): void
    {
        $ending = array_fill_keys($streams, true);
        $this->items = array_values(array_filter(
            $this->items,
            static fn (array $item): bool => $item['type'] !== 'lines' || ! isset($ending[$item['stream']]),
        ));

        foreach ($streams as $stream) {
            unset($this->queued[$stream], $this->buckets[$stream]);
            $this->ended[$stream] = $this->now() + self::EndedSeconds;
            $this->items[] = ['type' => 'end', 'item' => $this->nextItem++, 'node' => $nodeId, 'stream' => $stream, 'reason' => LogStreamEndReason::RelayBehind->value];
        }
    }

    /**
     * Keeps the lines that fit the stream's rate and counts the rest.
     *
     * @param  list<string>  $lines
     * @return array{list<string>, int}
     */
    private function limit(string $stream, array $lines): array
    {
        $now = $this->now();
        $bucket = $this->buckets[$stream] ?? ['tokens' => (float) self::BurstBytes, 'at' => $now];
        $bucket['tokens'] = min((float) self::BurstBytes, $bucket['tokens'] + ($now - $bucket['at']) * self::RateBytesPerSecond);
        $bucket['at'] = $now;
        $kept = [];
        $dropped = 0;

        foreach ($lines as $line) {
            $cost = strlen($line) + 1;

            if ($cost > $bucket['tokens']) {
                $dropped++;

                continue;
            }

            $bucket['tokens'] -= $cost;
            $kept[] = $line;
        }

        $this->buckets[$stream] = $bucket;

        return [$kept, $dropped];
    }

    private function line(string $line): string
    {
        $line = mb_scrub(str_replace("\r", '', $line), 'UTF-8');

        return strlen($line) > self::MaxLineBytes ? mb_strcut($line, 0, self::MaxLineBytes - 12, 'UTF-8').' [truncated]' : $line;
    }

    /** @param array<string, mixed> $data */
    private function streamId(array $data): ?string
    {
        $id = $data['stream'] ?? null;

        return is_string($id) && preg_match(LogStream::ID, $id) === 1 ? $id : null;
    }

    private function isEnded(string $stream): bool
    {
        return ($this->ended[$stream] ?? -INF) > $this->now();
    }

    private function noteEvent(): void
    {
        $this->streamsMayBeOpen = true;
        $this->eventDuringRun = true;
    }

    /** @param list<string> $lines */
    private static function bytes(array $lines): int
    {
        return array_sum(array_map(static fn (string $line): int => strlen($line) + 1, $lines));
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
