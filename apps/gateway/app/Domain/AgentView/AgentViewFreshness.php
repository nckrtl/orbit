<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

/**
 * How far a reader may trust the Gateway's view of one Node agent.
 *
 * Only a fresh view answers a read. A stale or missing view sends the reader to its fallback.
 */
enum AgentViewFreshness: string
{
    /** A complete snapshot exists, and an agent event arrived within the freshness window. */
    case Fresh = 'fresh';

    /** An entry exists, but no agent event arrived within the freshness window. */
    case Stale = 'stale';

    /** No entry exists: no subscriber, no Reverb, no agent member, or no complete snapshot yet. */
    case Missing = 'missing';
}
