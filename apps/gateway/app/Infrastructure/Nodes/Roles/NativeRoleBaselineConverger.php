<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\NodeAgentRoleConverger;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\Log;
use LogicException;

final readonly class NativeRoleBaselineConverger implements RoleBaselineConverger
{
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
        private ?DatabaseRoleBaseline $database = null,
        private ?WebSocketRoleBaseline $websocket = null,
        private ?AnalyticsRoleBaseline $analytics = null,
        private ?NodeAgentRoleConverger $agentConverger = null,
        private ?IngressRoleBaseline $ingress = null,
        private ?NodeRoleConvergeLock $nodeLock = null,
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
        $this->nodeLock()->run($node, function () use ($node, $assignment): void {
            $this->operatingSystem->assert($node, $assignment->role);
            $this->baseline($assignment->role)->converge($node, $assignment);
        });

        // The fleet reconcile converges exporters on other Nodes too, so it runs outside this Node's
        // lock: holding one Node's lock while it waits for another's could deadlock two converges.
        if ($assignment->role !== RoleName::Metrics) {
            $this->metricsFleet->reconcile();
        }

        try {
            $this->nodeLock()->run($node, fn () => $this->convergeAgent($node));
        } catch (NodeRoleOperationException $exception) {
            // Like any agent failure, a busy Node does not fail the role; the next converge repairs the agent.
            Log::warning('Node agent convergence skipped; another role operation holds the Node.', [
                'node_id' => $node->id,
                'node_name' => $node->name,
                'error' => $exception->underlyingErrorCode,
            ]);
        }
    }

    private function convergeAgent(Node $node): void
    {
        ($this->agentConverger ?? app(NodeAgentRoleConverger::class))->converge($node);
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
        $this->nodeLock()->run(
            $node,
            fn () => $this->baseline($assignment->role)->remove($node, $assignment, $purgeData),
            'node_role.remove_failed',
        );

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
            RoleName::WebSocket => $this->websocket ?? app(WebSocketRoleBaseline::class),
            RoleName::Analytics => $this->analytics ?? app(AnalyticsRoleBaseline::class),
            RoleName::Router => $this->router ?? app(RouterRoleBaseline::class),
            RoleName::Database => $this->database ?? app(DatabaseRoleBaseline::class),
            RoleName::Ingress => $this->ingress ?? app(IngressRoleBaseline::class),
        };
    }

    private function clusterId(NodeRole $assignment): int
    {
        if ($assignment->cluster_id === null) {
            throw new LogicException('A Router role assignment must belong to a Cluster.');
        }

        return $assignment->cluster_id;
    }

    private function nodeLock(): NodeRoleConvergeLock
    {
        return $this->nodeLock ?? app(NodeRoleConvergeLock::class);
    }

    private function routerOperations(): ClusterRouterOperationLock
    {
        return $this->clusterRouterOperations ?? app(ClusterRouterOperationLock::class);
    }
}
