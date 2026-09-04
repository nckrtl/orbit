<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteProvenance: string
{
    case Generated = 'generated';
    case Explicit = 'explicit';
}
