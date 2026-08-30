<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeRoleBaselineConverger implements RoleBaselineConverger
{
    /** @mago-expect lint:excessive-parameter-list The closed role registry requires one baseline per role plus fleet reconciliation. */
    public function __construct(
        private GatewayRoleBaseline $gateway,
        private VpnRoleBaseline $vpn,
        private AppDevRoleBaseline $appDev,
        private AppProdRoleBaseline $appProd,
        private MetricsRoleBaseline $metrics,
        private MetricsFleetReconciler $metricsFleet,
        private NodeRoleOperatingSystemGuard $operatingSystem,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->operatingSystem->assert($node, $assignment->role);
        $this->baseline($assignment->role)->converge($node, $assignment);

        if ($assignment->role !== RoleName::Metrics) {
            $this->metricsFleet->reconcile();
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->baseline($assignment->role)->remove($node, $assignment, $purgeData);

        if ($assignment->role !== RoleName::Metrics) {
            $this->metricsFleet->reconcile();
        }
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->baseline($assignment->role)->removeUnreachable($node, $assignment);

        if ($assignment->role !== RoleName::Metrics) {
            $this->metricsFleet->reconcile();
        }
    }

    private function baseline(RoleName $role): RoleBaseline
    {
        return match ($role) {
            RoleName::Gateway => $this->gateway,
            RoleName::Vpn => $this->vpn,
            RoleName::AppDev => $this->appDev,
            RoleName::AppProd => $this->appProd,
            RoleName::Metrics => $this->metrics,
        };
    }
}
