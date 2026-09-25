<?php

declare(strict_types=1);

namespace App\Domain\AgentView;

use Illuminate\Support\Carbon;

/** What the agent view subscriber last wrote about itself. */
final readonly class AgentViewSubscriberHealth
{
    public function __construct(
        public float $updatedAt,
        public bool $configured,
        public bool $connected,
        public int $channels,
    ) {}

    /** Whether the subscriber wrote its health recently enough to count as running. */
    public function isCurrent(): bool
    {
        return (float) Carbon::now()->format('U.u') - $this->updatedAt <= AgentStateView::SubscriberSeconds;
    }
}
