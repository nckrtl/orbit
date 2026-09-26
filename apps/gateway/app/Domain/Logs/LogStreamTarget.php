<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Models\Node;

/**
 * The Node that serves a record and the log source its agent reads for it. `sshOnly` marks a source
 * the agent cannot read, such as a production Instance's log outside the Instance root: its log is
 * read over SSH only.
 */
final readonly class LogStreamTarget
{
    public function __construct(
        public LogStreamRecordType $recordType,
        public int $recordId,
        public Node $node,
        public LogStreamSource $source,
        public bool $sshOnly = false,
    ) {}
}
