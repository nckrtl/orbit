<?php

declare(strict_types=1);

namespace App\Domain\Logs;

/** Why a live log stream ended, as `log.ended` reports it. */
enum LogStreamEndReason: string
{
    case Closed = 'closed';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case AgentLeft = 'agent_left';
    case SourceUnavailable = 'source_unavailable';
}
