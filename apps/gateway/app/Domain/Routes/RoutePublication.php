<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RoutePublication: string
{
    case Private = 'private';
    case Public = 'public';
}
