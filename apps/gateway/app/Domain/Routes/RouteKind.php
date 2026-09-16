<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteKind: string
{
    case App = 'app';
    case CustomProxy = 'custom_proxy';
}
