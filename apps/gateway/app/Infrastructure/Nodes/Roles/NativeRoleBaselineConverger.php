<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;
use LogicException;

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
        private ?RouterRoleBaseline $router = null,
        private ?ClusterRouterOperationLock $clusterRouterOperations = null,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role === RoleName::Router) {
            $this->routerOperations()->run(
                $this->clusterId($assignment),
                fn () => $this->convergeOwned($node, $assignment),
            );

            return;
        }

        $this->convergeOwned($node, $assignment);
    }

    private function convergeOwned(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role === RoleName::Ingress) {
            return;
        }

        $this->operatingSystem->assert($node, $assignment->role);
        $this->baseline($assignment->role)->converge($node, $assignment);

        if ($assignment->role !== RoleName::Metrics) {
            $this->metricsFleet->reconcile();
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        if ($assignment->role === RoleName::Router) {
            $this->routerOperations()->run(
                $this->clusterId($assignment),
                fn () => $this->removeOwned($node, $assignment, $purgeData),
            );

            return;
        }

        $this->removeOwned($node, $assignment, $purgeData);
    }

    private function removeOwned(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        if ($assignment->role === RoleName::Ingress) {
            return;
        }

        $this->baseline($assignment->role)->remove($node, $assignment, $purgeData);

        if ($assignment->role !== RoleName::Metrics) {
            $this->metricsFleet->reconcile();
        }
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role === RoleName::Router) {
            $this->routerOperations()->run(
                $this->clusterId($assignment),
                fn () => $this->removeUnreachableOwned($node, $assignment),
            );

            return;
        }

        $this->removeUnreachableOwned($node, $assignment);
    }

    private function removeUnreachableOwned(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role === RoleName::Ingress) {
            return;
        }

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
            RoleName::Router => $this->router ?? app(RouterRoleBaseline::class),
            RoleName::Ingress => throw new LogicException('Ingress roles do not have a host baseline.'),
        };
    }

    private function clusterId(NodeRole $assignment): int
    {
        if ($assignment->cluster_id === null) {
            throw new LogicException('A Router role assignment must belong to a Cluster.');
        }

        return $assignment->cluster_id;
    }

    private function routerOperations(): ClusterRouterOperationLock
    {
        return $this->clusterRouterOperations ?? app(ClusterRouterOperationLock::class);
    }
}
