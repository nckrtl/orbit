<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskThreadState: string
{
    case Idle = 'idle';
    case Pending = 'pending';
    case Finished = 'finished';

    /**
     * Maps a T3 `thread.session.status` onto the state the viewer shows.
     *
     * T3 reports `ready` for a session that waits, `running` while a turn is
     * in flight, and `stopped` once the runtime is gone.
     */
    public static function fromSessionStatus(mixed $status): ?self
    {
        if (! is_string($status)) {
            return null;
        }

        return match ($status) {
            'ready', 'idle' => self::Idle,
            'running', 'pending' => self::Pending,
            'stopped', 'finished' => self::Finished,
            default => null,
        };
    }
}
