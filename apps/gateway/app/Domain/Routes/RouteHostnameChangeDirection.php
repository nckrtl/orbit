<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteHostnameChangeDirection: string
{
    case Forward = 'forward';
    case Rollback = 'rollback';
}
