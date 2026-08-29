<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Models\Node;

final class NodeBootstrapPackageCatalog
{
    /** @return list<string> */ public function forNode(Node $node): array
    {
        return ['ca-certificates', 'curl', 'gnupg', 'openssh-client', 'sudo', 'ufw', 'wireguard'];
    }

    /** @return list<string> */ public function forRole(Node $node, RoleName $role): array
    {
        return match ($role) {
            RoleName::Gateway => ['ca-certificates'],
            RoleName::Vpn => ['dnsmasq', 'openssl'],
            RoleName::AppDev, RoleName::AppProd => [
                'acl',
                'attr',
                'caddy',
                'composer',
                'docker.io',
                'git',
                'openssl',
                'unzip',
            ],
        };
    }
}
