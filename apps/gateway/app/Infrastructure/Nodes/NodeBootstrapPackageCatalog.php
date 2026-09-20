<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Models\Node;

final class NodeBootstrapPackageCatalog
{
    /** @var list<string> */
    private const array PHP_COMPOSER_HOST_PACKAGES = [
        'php-curl',
        'php-xml',
    ];

    /** @return list<string> */
    public function forNode(Node $node): array
    {
        return ['ca-certificates', 'curl', 'gnupg', 'libnss-resolve', 'openssh-client', 'sudo', 'ufw', 'wireguard'];
    }

    /** @return list<string> */
    public function forRole(Node $node, RoleName $role): array
    {
        return match ($role) {
            RoleName::Gateway => ['ca-certificates'],
            RoleName::Vpn => ['dnsmasq', 'openssl'],
            RoleName::Router => ['caddy', 'openssl'],
            RoleName::Ingress => [],
            RoleName::AppDev, RoleName::AppProd => [
                'acl',
                'attr',
                'caddy',
                'composer',
                'docker.io',
                'git',
                'openssl',
                ...self::PHP_COMPOSER_HOST_PACKAGES,
                'unzip',
            ],
            RoleName::Metrics => ['docker.io', 'openssl'],
            RoleName::Database => ['docker.io'],
            RoleName::WebSocket => [
                'caddy',
                'composer',
                'git',
                'openssl',
                ...self::PHP_COMPOSER_HOST_PACKAGES,
            ],
            RoleName::Analytics => ['caddy', 'docker.io', 'openssl'],
        };
    }
}
