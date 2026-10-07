<?php

declare(strict_types=1);

namespace App\Domain\Releases;

enum ReleaseAlertKind: string
{
    case ReleaseFailed = 'release_failed';
    case ReleasePaused = 'release_paused';
    case ReleaseStalled = 'release_stalled';
    case ReleaseCleanupPaused = 'release_cleanup_paused';
    case RolloutHalted = 'rollout_halted';

    public function label(): string
    {
        return match ($this) {
            self::ReleaseFailed => 'Release failed',
            self::ReleasePaused => 'Release paused',
            self::ReleaseStalled => 'Release stalled',
            self::ReleaseCleanupPaused => 'Document cleanup paused after release',
            self::RolloutHalted => 'Rollout halted',
        };
    }
}
