<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamAvailability;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Logs\LogStreamTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;

/**
 * Opens a live log stream for one viewer (ADR 0153): checks that the serving Node can stream,
 * stores the stream with a lease, and signs the viewer's socket for the stream's private channel.
 * The stream stays inactive until the viewer's first renewal, so the agent starts reading only
 * after the viewer subscribed.
 */
final readonly class OpenLogStreamAction
{
    public function __construct(
        private LogStreamAvailability $availability,
        private LogStreamStore $streams,
        private RealtimeConnection $realtime,
    ) {}

    /** @return array{stream: LogStream, auth: string} */
    public function execute(LogStreamTarget $target, Node $viewer, string $socketId, int $lines): array
    {
        $nodeId = (int) $target->node->getKey();
        $reason = $this->availability->unavailableReason($nodeId);
        $connection = $this->realtime->resolve();

        if ($reason !== null || $connection === null) {
            $reason ??= 'realtime_not_configured';

            throw new ResourceOperationException(
                errorCode: 'logs.live_unavailable',
                message: "The live log path is not available for Node [{$target->node->name}] ({$reason}).",
                status: 409,
                details: ['reason' => $reason],
            );
        }

        $stream = new LogStream(
            id: bin2hex(random_bytes(16)),
            recordType: $target->recordType,
            recordId: $target->recordId,
            nodeId: $nodeId,
            viewerNodeId: (int) $viewer->getKey(),
            source: $target->source,
            lines: $lines,
            expiresAt: CacheAgentStateView::now() + LogStreamStore::LeaseSeconds,
        );

        if (! $this->streams->open($stream)) {
            throw new ResourceOperationException(
                errorCode: 'logs.stream_limit',
                message: "Node [{$target->node->name}] already has ".LogStreamStore::MaxPerNode.' open log streams.',
                status: 429,
            );
        }

        $channel = $stream->channel();

        return [
            'stream' => $stream,
            'auth' => $connection->key.':'.hash_hmac('sha256', "{$socketId}:{$channel}", $connection->secret),
        ];
    }
}
