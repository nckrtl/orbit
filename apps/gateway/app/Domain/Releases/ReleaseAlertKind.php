<?php

declare(strict_types=1);

namespace App\Domain\Releases;

enum ReleaseAlertKind: string
{
    case ReleaseFailed = 'release_failed';
    case ReleasePaused = 'release_paused';
    case ReleaseStalled = 'release_stalled';
    case ReleaseCleanupPaused = 'release_cleanup_paused';
    case ReleaseSchedulerSilent = 'release_scheduler_silent';
    case ReleaseGatewayAgentFailed = 'release_gateway_agent_failed';
    case RolloutHalted = 'rollout_halted';
    case RolloutStalled = 'rollout_stalled';
    case RolloutCaddySkipped = 'rollout_caddy_skipped';

    public function label(): string
    {
        return match ($this) {
            self::ReleaseFailed => 'Release failed',
            self::ReleasePaused => 'Release paused',
            self::ReleaseStalled => 'Release stalled',
            self::ReleaseCleanupPaused => 'Document cleanup paused after release',
            self::ReleaseSchedulerSilent => 'Scheduler silent after release',
            self::ReleaseGatewayAgentFailed => 'Gateway agent update failed after release',
            self::RolloutHalted => 'Rollout halted',
            self::RolloutStalled => 'Rollout stalled',
            self::RolloutCaddySkipped => 'Rollout kept a live Caddyfile',
        };
    }
}
