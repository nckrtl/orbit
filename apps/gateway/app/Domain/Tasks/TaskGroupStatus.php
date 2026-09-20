<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskGroupStatus: string
{
    case Queued = 'queued';
    case Reserved = 'reserved';
    case Running = 'running';
    case Reviewing = 'reviewing';
    case Settling = 'settling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function active(): array
    {
        return [
            self::Reserved,
            self::Running,
            self::Reviewing,
            self::Settling,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
