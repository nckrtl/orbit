<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamBroadcaster;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;

/**
 * Extends the lease of a live log stream that the calling Node opened for this record. The first
 * renewal activates the stream and prompts the Node's agent to start reading (ADR 0153). Each later
 * renewal prompts again until the stream relays its first line: a lost prompt leaves the stream
 * silent, and the relay's own prompts run only while the subscriber knows a stream may be open.
 */
final readonly class RenewLogStreamAction
{
    public function __construct(
        private LogStreamStore $streams,
        private LogStreamBroadcaster $broadcaster,
    ) {}

    public function execute(LogStreamRecordType $recordType, int $recordId, string $streamId, Node $viewer): LogStream
    {
        $stream = $this->streams->find($streamId);

        if (! self::owns($stream, $recordType, $recordId, $viewer)) {
            throw self::notFound();
        }

        $renewed = $this->streams->renew($streamId, CacheAgentStateView::now() + LogStreamStore::LeaseSeconds) ?? throw self::notFound();

        if (! $stream->active || $this->streams->cursor($streamId) === null) {
            $this->broadcaster->changed($renewed->nodeId);
        }

        return $renewed;
    }

    /** @phpstan-assert-if-true LogStream $stream */
    public static function owns(?LogStream $stream, LogStreamRecordType $recordType, int $recordId, Node $viewer): bool
    {
        return $stream !== null
            && $stream->recordType === $recordType
            && $stream->recordId === $recordId
            && $stream->viewerNodeId === (int) $viewer->getKey();
    }

    /** One answer for a stream that is gone, belongs to another record, or was opened by another Node. */
    public static function notFound(): ResourceOperationException
    {
        return new ResourceOperationException('logs.stream_not_found', 'The log stream does not exist.', 404);
    }
}
