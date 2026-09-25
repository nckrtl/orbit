<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

use App\Domain\Logs\LogRedactor;
use App\Domain\Logs\LogRelayCursor;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamBroadcaster;
use App\Domain\Logs\LogStreamEndReason;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Psr\Log\LoggerInterface;

/**
 * One relay run of live log events (ADR 0153). `orbit:agent-view-publish --logs` runs it outside the
 * agent view subscriber's socket loop, with the items that {@see LogRelayQueue} kept in order.
 *
 * It relays lines only for a stream that is open for the sending Node. It redacts them again with the
 * record's stored environment values and the Gateway's secret patterns, and publishes them on the
 * viewer's private channel in parts under Reverb's message limit. After each part it saves the
 * stream's {@see LogRelayCursor}, so a repeated run skips what it already published. A failed
 * publish or store read throws, and the subscriber runs the items again.
 *
 * It also ends streams: when the agent reports its source unavailable, when the agent leaves, when the
 * queue fell behind, and, when asked to sweep, when a lease ran out or a viewer lost access. When
 * asked to prompt, it sends `log-streams.changed` again to each Node with an active stream that has
 * not relayed a line yet, because that Node's agent may have missed the first prompt.
 *
 * Log lines are never logged.
 *
 * @phpstan-import-type Batch from LogRelayQueue
 */
final readonly class LogRelay
{
    public function __construct(
        private LogStreamStore $streams,
        private LogStreamBroadcaster $broadcaster,
        private LogRedactor $redactor,
        private NodeAccessAuthorizer $access,
        private LoggerInterface $log,
    ) {}

    /**
     * Relays one batch in order and returns how many streams are still open.
     *
     * @param  Batch  $batch
     */
    public function relay(array $batch): int
    {
        /** @var array<string, list<string>|null> $values Environment values by stream, read once per run. */
        $values = [];

        foreach ($batch['items'] as $item) {
            match ($item['type']) {
                'lines' => $this->lines($batch['relay'], $item['item'], $item['node'], $item['stream'], $item['lines'], $item['dropped'], $item['skipped'], $values),
                'end' => $this->endStream($item['node'], $item['stream'], LogStreamEndReason::tryFrom($item['reason']) ?? LogStreamEndReason::SourceUnavailable),
                'agent_left' => $this->agentLeft($item['node']),
            };
        }

        if ($batch['sweep']) {
            $this->sweep($batch['prompt']);
        }

        return count($this->streams->all());
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, list<string>|null>  $values
     */
    private function lines(string $relay, int $item, int $nodeId, string $streamId, array $lines, int $dropped, int $skipped, array &$values): void
    {
        $stream = $this->streams->find($streamId);

        // A Node may send lines only for the streams whose source is on that Node. A closed stream has no viewer.
        if ($stream === null || $stream->nodeId !== $nodeId) {
            return;
        }

        if (! array_key_exists($streamId, $values)) {
            $record = $this->record($stream);
            $values[$streamId] = $record === null ? null : $this->redactor->valuesFor($record);
        }

        if ($values[$streamId] === null) {
            $this->finish($stream, LogStreamEndReason::SourceUnavailable);

            return;
        }

        /** @var list<string> $lines */
        $lines = $lines === [] ? [] : explode("\n", $this->redactor->redact(implode("\n", $lines), $values[$streamId]));
        $cursor = $this->streams->cursor($streamId);
        $sequence = $cursor->sequence ?? 0;

        foreach ($this->parts($streamId, $lines, $dropped, $skipped) as $index => $part) {
            if ($cursor?->covers($relay, $item, $index + 1) === true) {
                continue;
            }

            $this->broadcaster->lines($streamId, ++$sequence, $part['lines'], $part['dropped'], $part['skipped']);
            $cursor = new LogRelayCursor($relay, $item, $index + 1, $sequence);
            $this->streams->saveCursor($streamId, $cursor);
        }
    }

    /**
     * Splits lines into `log.lines` events under Reverb's message limit. The counts go with the first.
     *
     * @param  list<string>  $lines
     * @return list<array{lines: list<string>, dropped: int, skipped: int}>
     */
    private function parts(string $streamId, array $lines, int $dropped, int $skipped): array
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

        if ($parts === [[]] && $dropped === 0 && $skipped === 0) {
            return [];
        }

        return array_map(
            static fn (array $lines, int $index): array => ['lines' => $lines, 'dropped' => $index === 0 ? $dropped : 0, 'skipped' => $index === 0 ? $skipped : 0],
            $parts,
            array_keys($parts),
        );
    }

    private function endStream(int $nodeId, string $streamId, LogStreamEndReason $reason): void
    {
        $stream = $this->streams->find($streamId);

        if ($stream !== null && $stream->nodeId === $nodeId) {
            $this->finish($stream, $reason);
        }
    }

    private function agentLeft(int $nodeId): void
    {
        foreach ($this->streams->all() as $stream) {
            if ($stream->nodeId === $nodeId) {
                $this->finish($stream, LogStreamEndReason::AgentLeft, prompt: false);
            }
        }
    }

    /** Ends streams whose lease ran out or whose viewer lost access to the Node, and prompts again when asked. */
    private function sweep(bool $prompt): void
    {
        $changed = [];

        foreach ($this->streams->sweep() as $stream) {
            $this->broadcaster->ended($stream->id, LogStreamEndReason::Expired);
            $changed[$stream->nodeId] = true;
        }

        foreach ($this->streams->all() as $stream) {
            if (! $this->allowed($stream)) {
                $this->finish($stream, LogStreamEndReason::Revoked, prompt: false);
                $changed[$stream->nodeId] = true;
            } elseif ($prompt && $stream->active && $this->streams->cursor($stream->id) === null) {
                $changed[$stream->nodeId] = true;
            }
        }

        foreach (array_keys($changed) as $nodeId) {
            $this->broadcaster->changed($nodeId);
        }
    }

    private function record(LogStream $stream): AppInstance|Process|null
    {
        return match ($stream->recordType) {
            LogStreamRecordType::Instance => AppInstance::query()->with('environmentValues')->find($stream->recordId),
            LogStreamRecordType::Process => Process::query()->find($stream->recordId),
        };
    }

    private function allowed(LogStream $stream): bool
    {
        $viewer = Node::query()->find($stream->viewerNodeId);
        $serving = Node::query()->find($stream->nodeId);

        return $viewer instanceof Node && $serving instanceof Node && $this->access->allows($viewer, $serving);
    }

    /** Closes the stream, then tells its viewer and, when `$prompt`, its agent. Both messages are best effort. */
    private function finish(LogStream $stream, LogStreamEndReason $reason, bool $prompt = true): void
    {
        $this->streams->close($stream->id);
        $this->logEnd($stream, $reason);
        $this->broadcaster->ended($stream->id, $reason);

        if ($prompt) {
            $this->broadcaster->changed($stream->nodeId);
        }
    }

    private function logEnd(LogStream $stream, LogStreamEndReason $reason): void
    {
        $context = ['stream' => $stream->id, 'node_id' => $stream->nodeId, 'reason' => $reason->value];

        if ($reason === LogStreamEndReason::RelayBehind) {
            $this->log->warning('The log relay fell behind and ended a live log stream.', $context);
        } else {
            $this->log->info('A live log stream ended.', $context);
        }
    }
}
