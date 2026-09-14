<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Activating = 'activating';
    case Retiring = 'retiring';
    case Failed = 'failed';

    public function isAuthoritative(): bool
    {
        return $this === self::Active || $this === self::Activating;
    }
}
