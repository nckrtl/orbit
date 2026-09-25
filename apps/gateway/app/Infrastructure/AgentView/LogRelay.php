<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\Logs\LogRedactor;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamBroadcaster;
use App\Domain\Logs\LogStreamEndReason;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The agent view subscriber's relay of live log lines (ADR 0153).
 *
 * It accepts lines only for a stream that is open for the sending Node, redacts them again with the
 * record's stored environment values and the Gateway's secret patterns, drops lines above its own
 * rate, and publishes them on the viewer's private channel in parts under Reverb's message limit.
 * It also ends streams whose lease ran out, whose viewer lost access, or whose agent left.
 *
 * Log lines are never logged.
 */
final class LogRelay
{
    public const int RateBytesPerSecond = 65_536;

    public const int BurstBytes = 262_144;

    public const int SweepSeconds = 5;

    /** Seconds before the relay reads a record's environment values again. */
    public const int ValuesSeconds = 60;

    /** The longest line the relay accepts; the agent already cuts lines at 8 KiB. */
    public const int MaxLineBytes = 8_192 + 64;

    /** The most lines one agent event may carry. */
    public const int MaxLines = 2_000;

    /** @var array<string, array{sequence: int, tokens: float, at: float, values: list<string>, valuesAt: float}> */
    private array $relayed = [];

    private float $nextSweepAt = 0.0;

    /** @param Closure(): float $clock */
    public function __construct(
        private readonly LogStreamStore $streams,
        private readonly LogStreamBroadcaster $broadcaster,
        private readonly LogRedactor $redactor,
        private readonly NodeAccessAuthorizer $access,
        private readonly LoggerInterface $log,
        private readonly Closure $clock,
    ) {}

    /**
     * Relays one `client-log` event that Reverb stamped with `agent.{nodeId}`.
     *
     * @param  array<string, mixed>  $data
     */
    public function lines(int $nodeId, array $data): void
    {
        $stream = $this->streamOf($nodeId, $data);
        $lines = $data['lines'] ?? null;
        $dropped = $data['dropped'] ?? 0;
        $skipped = $data['skipped'] ?? 0;

        if (
            $stream === null || ! is_array($lines) || ! array_is_list($lines) || count($lines) > self::MaxLines
            || ! array_all($lines, static fn (mixed $line): bool => is_string($line)) || ! is_int($dropped) || $dropped < 0 || ! is_int($skipped) || $skipped < 0
        ) {
            return;
        }

        $state = $this->state($stream);

        if ($state === null) {
            return;
        }

        /** @var list<string> $lines */
        $lines = $lines === [] ? [] : explode("\n", $this->redactor->redact(implode("\n", array_map($this->line(...), $lines)), $state['values']));
        [$kept, $limited] = $this->limit($stream->id, $lines);
        $this->publish($stream->id, $kept, $dropped + $limited, $skipped);
    }

    /**
     * Ends one stream after its agent reported `client-log-end`.
     *
     * @param  array<string, mixed>  $data
     */
    public function end(int $nodeId, array $data): void
    {
        $stream = $this->streamOf($nodeId, $data);

        if ($stream !== null) {
            $this->finish($stream, LogStreamEndReason::SourceUnavailable);
        }
    }

    /** Ends every stream of a Node whose agent left its log channel. */
    public function agentLeft(int $nodeId): void
    {
        foreach ($this->streams->all() as $stream) {
            if ($stream->nodeId === $nodeId) {
                $this->finish($stream, LogStreamEndReason::AgentLeft, prompt: false);
            }
        }
    }

    /** Every `SweepSeconds`: ends streams whose lease ran out or whose viewer lost access to the Node. */
    public function sweep(): void
    {
        if ($this->now() < $this->nextSweepAt) {
            return;
        }

        $this->nextSweepAt = $this->now() + self::SweepSeconds;

        try {
            $changed = [];

            foreach ($this->streams->sweep() as $stream) {
                $this->broadcaster->ended($stream->id, LogStreamEndReason::Expired);
                $changed[$stream->nodeId] = true;
            }

            $open = [];

            foreach ($this->streams->all() as $stream) {
                $open[$stream->id] = true;

                if (! $this->allowed($stream)) {
                    $this->finish($stream, LogStreamEndReason::Revoked, prompt: false);
                    $changed[$stream->nodeId] = true;
                }
            }

            foreach (array_keys($changed) as $nodeId) {
                $this->broadcaster->changed($nodeId);
            }

            $this->relayed = array_intersect_key($this->relayed, $open);
        } catch (Throwable $exception) {
            $this->log->warning('The log relay could not check the open log streams.', ['error' => $exception->getMessage()]);
        }
    }

    /** @param array<string, mixed> $data */
    private function streamOf(int $nodeId, array $data): ?LogStream
    {
        $id = $data['stream'] ?? null;

        if (! is_string($id) || preg_match(LogStream::ID, $id) !== 1) {
            return null;
        }

        try {
            $stream = $this->streams->find($id);
        } catch (Throwable $exception) {
            $this->log->warning('The log relay could not read a log stream.', ['stream' => $id, 'error' => $exception->getMessage()]);

            return null;
        }

        // A Node may send lines only for the streams whose source is on that Node.
        return $stream !== null && $stream->nodeId === $nodeId ? $stream : null;
    }

    /** @return array{sequence: int, tokens: float, at: float, values: list<string>, valuesAt: float}|null */
    private function state(LogStream $stream): ?array
    {
        $state = $this->relayed[$stream->id] ?? ['sequence' => 0, 'tokens' => (float) self::BurstBytes, 'at' => $this->now(), 'values' => [], 'valuesAt' => -INF];

        if ($this->now() - $state['valuesAt'] >= self::ValuesSeconds) {
            $record = $this->record($stream);

            if ($record === null) {
                $this->finish($stream, LogStreamEndReason::SourceUnavailable);

                return null;
            }

            $state['values'] = $this->redactor->valuesFor($record);
            $state['valuesAt'] = $this->now();
        }

        return $this->relayed[$stream->id] = $state;
    }

    private function record(LogStream $stream): AppInstance|Process|null
    {
        try {
            return match ($stream->recordType) {
                LogStreamRecordType::Instance => AppInstance::query()->with('environmentValues')->find($stream->recordId),
                LogStreamRecordType::Process => Process::query()->find($stream->recordId),
            };
        } catch (Throwable $exception) {
            $this->log->warning('The log relay could not read the record of a log stream.', ['stream' => $stream->id, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    private function line(string $line): string
    {
        $line = mb_scrub(str_replace("\r", '', $line), 'UTF-8');

        return strlen($line) > self::MaxLineBytes ? mb_strcut($line, 0, self::MaxLineBytes - 12, 'UTF-8').' [truncated]' : $line;
    }

    /**
     * Keeps the lines that fit the stream's rate and counts the rest.
     *
     * @param  list<string>  $lines
     * @return array{list<string>, int}
     */
    private function limit(string $streamId, array $lines): array
    {
        $state = $this->relayed[$streamId];
        $state['tokens'] = min((float) self::BurstBytes, $state['tokens'] + ($this->now() - $state['at']) * self::RateBytesPerSecond);
        $state['at'] = $this->now();
        $kept = [];
        $dropped = 0;

        foreach ($lines as $line) {
            $cost = strlen($line) + 1;

            if ($cost > $state['tokens']) {
                $dropped++;

                continue;
            }

            $state['tokens'] -= $cost;
            $kept[] = $line;
        }

        $this->relayed[$streamId] = $state;

        return [$kept, $dropped];
    }

    /** @param list<string> $lines */
    private function publish(string $streamId, array $lines, int $dropped, int $skipped): void
    {
        $parts = [];
        $part = [];

        foreach ($lines as $line) {
            if ($part !== [] && LogStreamBroadcaster::linesPayloadSize($streamId, [...$part, $line]) > LogStreamBroadcaster::PayloadLimit) {
                $parts[] = $part;
                $part = [];
            }

            $part[] = $line;
        }

        if ($part !== [] || $parts === []) {
            $parts[] = $part;
        }

        foreach ($parts as $index => $part) {
            if ($part === [] && $dropped === 0 && $skipped === 0) {
                continue;
            }

            $sequence = ++$this->relayed[$streamId]['sequence'];
            $this->broadcaster->lines($streamId, $sequence, $part, $index === 0 ? $dropped : 0, $index === 0 ? $skipped : 0);
        }
    }

    private function allowed(LogStream $stream): bool
    {
        $viewer = Node::query()->find($stream->viewerNodeId);
        $serving = Node::query()->find($stream->nodeId);

        return $viewer instanceof Node && $serving instanceof Node && $this->access->allows($viewer, $serving);
    }

    private function finish(LogStream $stream, LogStreamEndReason $reason, bool $prompt = true): void
    {
        try {
            $this->streams->close($stream->id);
        } catch (Throwable $exception) {
            $this->log->warning('The log relay could not close a log stream.', ['stream' => $stream->id, 'error' => $exception->getMessage()]);
        }

        unset($this->relayed[$stream->id]);
        $this->broadcaster->ended($stream->id, $reason);

        if ($prompt) {
            $this->broadcaster->changed($stream->nodeId);
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
