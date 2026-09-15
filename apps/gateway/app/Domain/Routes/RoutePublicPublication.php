<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RoutePublicPublication: string
{
    case Inactive = 'inactive';
    case Active = 'active';
}
