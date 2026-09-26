<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamBroadcaster;
use App\Domain\Logs\LogStreamEndReason;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamStore;
use App\Models\Node;

/** Closes a live log stream that the calling Node opened, tells the viewer, and prompts the agent. */
final readonly class CloseLogStreamAction
{
    public function __construct(
        private LogStreamStore $streams,
        private LogStreamBroadcaster $broadcaster,
    ) {}

    public function execute(LogStreamRecordType $recordType, int $recordId, string $streamId, Node $viewer): LogStream
    {
        $stream = $this->streams->find($streamId);

        if (! RenewLogStreamAction::owns($stream, $recordType, $recordId, $viewer)) {
            throw RenewLogStreamAction::notFound();
        }

        $closed = $this->streams->close($streamId) ?? throw RenewLogStreamAction::notFound();
        $this->broadcaster->ended($closed->id, LogStreamEndReason::Closed);
        $this->broadcaster->changed($closed->nodeId);

        return $closed;
    }
}
