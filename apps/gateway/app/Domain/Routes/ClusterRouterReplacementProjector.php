<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

interface ClusterRouterReplacementProjector
{
    public function prepareRouterCertificate(Route $route, Node $router, AppInstance $workload): void;

    public function prepareFirewallPolicy(Route $route, Node $router, AppInstance $workload): void;

    public function verifyWorkload(Route $route, Node $router, AppInstance $workload): void;

    public function prepareRouterCaddy(Route $route, Node $router): void;

    public function publishDns(Route $route, Node $router): void;

    public function cleanupOldRouter(Route $route, Node $oldRouter): void;

    public function restore(Route $route, Node $newRouter, ?Node $oldRouter): void;
}
