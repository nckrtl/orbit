<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Models\AppInstance;
use App\Models\Route;

final readonly class NativeProductionRouteProjector implements ProductionRouteProjector
{
    public function __construct(
        private RemoteAppDevPhpFpmManager $php,
        private RemoteAppDevCertificateManager $certificates,
        private NodeRoleFirewallManager $firewall,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
    ) {}

    public function prepareRuntime(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $this->php->convergeRoute($appInstance->node, $route);
    }

    public function prepareCertificate(AppInstance $appInstance, Route $route): void
    {
        $this->certificates->convergeAppInstance($appInstance, $route);
    }

    public function prepareFirewall(AppInstance $appInstance): void
    {
        $appInstance->loadMissing('node');
        $this->firewall->converge($appInstance->node, RoleName::AppProd, $appInstance->node->user);
    }

    public function publish(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $this->caddy->convergeRoute($appInstance->node, $route);
        $this->dns->convergeRoute($route);
    }
}
