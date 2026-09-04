<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteStatus: string
{
    case Pending = 'pending';
}
