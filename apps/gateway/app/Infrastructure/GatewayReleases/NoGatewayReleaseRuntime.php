<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseRuntime;

/**
 * Handoff placeholder. The migration and runtime slice replaces it with the handoff that reloads
 * Caddy, restarts the scheduler, reconciles document cleanup, and restarts agent-view. Switch-back
 * still calls it, so the release flow has one handoff on the way forward and one on the way back.
 */
final class NoGatewayReleaseRuntime implements GatewayReleaseRuntime
{
    public function handoff(string $id): array
    {
        return [
            'caddy' => 'skipped',
            'fpm' => 'skipped',
            'scheduler' => 'skipped',
            'cleanup' => 'skipped',
            'agent_view' => 'skipped',
            'cleanup_paused' => false,
        ];
    }
}
