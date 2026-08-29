<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;

final class NodeRoleServiceCatalog
{
    /** @return list<string> */ public function forRole(RoleName $role): array
    {
        return match ($role) {
            RoleName::Gateway => ['caddy', 'php8.5-fpm'],
            RoleName::Vpn => ['wg-quick@orbit', 'dnsmasq'],
            RoleName::AppDev, RoleName::AppProd => ['caddy', 'docker'],
        };
    }
}
