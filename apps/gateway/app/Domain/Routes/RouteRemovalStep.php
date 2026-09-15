<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteRemovalStep: string
{
    case Dns = 'dns';
    case Certificates = 'certificates';
    case Caddy = 'caddy';
    case Firewall = 'firewall';
    case Record = 'record';
}
