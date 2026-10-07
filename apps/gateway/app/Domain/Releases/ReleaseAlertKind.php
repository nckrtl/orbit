<?php

declare(strict_types=1);

namespace App\Domain\Releases;

enum ReleaseAlertKind: string
{
    case ReleaseFailed = 'release_failed';
    case ReleasePaused = 'release_paused';
    case RolloutHalted = 'rollout_halted';

    public function label(): string
    {
        return match ($this) {
            self::ReleaseFailed => 'Release failed',
            self::ReleasePaused => 'Release paused',
            self::RolloutHalted => 'Rollout halted',
        };
    }
}
