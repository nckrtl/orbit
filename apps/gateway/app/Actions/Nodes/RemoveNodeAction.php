<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\RemoveNodeData;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Models\Node;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity Removal keeps guarded projection rollback in one transaction flow.
 * @mago-expect lint:halstead Removal keeps the ordered recovery boundary visible in one transaction flow.
 * @mago-expect lint:kan-defect Removal keeps guarded projection rollback in one transaction flow.
 * @mago-expect lint:too-many-methods Removal keeps its guards, role shedding and ordered recovery in one boundary.
 */
final readonly class RemoveNodeAction
{
    /** @mago-expect lint:excessive-parameter-list Removal requires each narrow lifecycle collaborator explicitly. */
    public function __construct(
        private PrivateDnsManager $dns,
        private GatewayPeerProjectionManager $peers,
        private MetricsFleetReconciler $metrics,
        private NodeReachabilityProbe $reachability,
        private RemoveNodeRoleAction $roles,
        private NodeSideResidue $residue,
        private ?RouteRemovalGuard $routes = null,
    ) {}

    /** @mago-expect lint:no-boolean-flag-parameter The public removal contract carries the explicit claim and consent. */
    public function execute(
        Node $node,
        Node $caller,
        bool $offline = false,
        bool $force = false,
    ): RemoveNodeData {
        ($this->routes ?? app(RouteRemovalGuard::class))->assertNodeRemovable($node);
        $this->guardProtected($node, $caller);
        $shed = $offline ? $this->shedRoles($node, $force) : null;
        $this->guardRemoval($node);
        $peerRemoved = false;
        $result = new RemoveNodeData(
            id: $node->id,
            name: $node->name,
            removed: true,
            wireguardPeerRemoved: $node->wireguard_public_key !== null,
            dnsRecordsRemoved: true,
            degradation: $shed === null ? null : ExporterDegradationReason::Unreachable->value,
            rolesShed: $shed ?? [],
            retainedOnNode: $shed === null
                ? []
                : $this->residue->describe(
                    array_values(array_map(RoleName::from(...), $shed)),
                    nodeLeavesFleet: true,
                ),
            followUp: $shed === null ? null : $this->residue->followUp(nodeLeavesFleet: true),
        );
        $node->update(['status' => LifecycleStatus::Removing]);

        try {
            $this->metrics->retire($node);
        } catch (Throwable $exception) {
            $node->update(['status' => LifecycleStatus::Active]);
            $rollbackFailure = $this->restoreMetricsSelection();

            if ($rollbackFailure instanceof Throwable) {
                throw $this->metricsRollbackFailure($node, $rollbackFailure);
            }

            throw $this->failure(
                step: 'metrics-exporters',
                errorCode: 'node.metrics_reconcile_failed',
                message: "Could not retire Metrics exporter state for node [{$node->name}].",
                previous: $exception,
            );
        }

        if ($node->wireguard_public_key !== null) {
            try {
                $this->peers->remove($node);
                $peerRemoved = true;
            } catch (Throwable $exception) {
                $node->update(['status' => LifecycleStatus::Active]);
                $rollbackFailure = $this->restoreMetricsSelection();

                if ($rollbackFailure instanceof Throwable) {
                    throw $this->metricsRollbackFailure($node, $rollbackFailure);
                }

                throw $this->failure(
                    step: 'wireguard-projection',
                    errorCode: 'node.wireguard_projection_failed',
                    message: "Could not remove the WireGuard peer for node [{$node->name}].",
                    previous: $exception,
                );
            }
        }

        try {
            $this->dns->converge();
        } catch (Throwable $exception) {
            $node->update(['status' => LifecycleStatus::Active]);
            $rollbackFailure = null;

            if ($peerRemoved) {
                try {
                    $this->peers->restore($node);
                } catch (Throwable $rollbackException) {
                    $rollbackFailure = $rollbackException;
                }
            }

            $metricsRollbackFailure = $this->restoreMetricsSelection();

            if ($rollbackFailure instanceof Throwable) {
                throw $this->failure(
                    step: 'wireguard-rollback',
                    errorCode: 'node.removal_rollback_failed',
                    message: "Could not restore the WireGuard peer for node [{$node->name}].",
                    previous: $rollbackFailure,
                );
            }

            if ($metricsRollbackFailure instanceof Throwable) {
                throw $this->metricsRollbackFailure($node, $metricsRollbackFailure);
            }

            throw $this->failure(
                step: 'dns-projection',
                errorCode: 'node.dns_projection_failed',
                message: "Could not remove the DNS records for node [{$node->name}].",
                previous: $exception,
            );
        }

        try {
            $node->delete();
        } catch (Throwable $exception) {
            $node->update(['status' => LifecycleStatus::Active]);
            $rollbackFailure = null;

            if ($peerRemoved) {
                try {
                    $this->peers->restore($node);
                } catch (Throwable $peerRollbackException) {
                    $rollbackFailure = $peerRollbackException;
                }
            }

            try {
                $this->dns->converge();
            } catch (Throwable $dnsRollbackException) {
                $rollbackFailure ??= $dnsRollbackException;
            }

            $metricsRollbackFailure = $this->restoreMetricsSelection();

            if ($rollbackFailure instanceof Throwable) {
                throw $this->failure(
                    step: 'persistence-rollback',
                    errorCode: 'node.removal_rollback_failed',
                    message: "Could not restore network projections for node [{$node->name}].",
                    previous: $rollbackFailure,
                );
            }

            if ($metricsRollbackFailure instanceof Throwable) {
                throw $this->metricsRollbackFailure($node, $metricsRollbackFailure);
            }

            throw $this->failure(
                step: 'persistence',
                errorCode: 'node.persistence_failed',
                message: "Could not remove node [{$node->name}] from gateway state.",
                previous: $exception,
            );
        }

        return $result;
    }

    /**
     * The guards that hold whatever the node's state is.
     *
     * They run before any role is shed, so an offline Gateway or VPN node is
     * never taken apart on the way to a refusal.
     */
    private function guardProtected(Node $node, Node $caller): void
    {
        if ($node->appInstances()->exists()) {
            throw $this->conflict(
                'node.has_app_instances',
                "Node [{$node->name}] still owns AppInstances.",
            );
        }

        if ($node->is($caller)) {
            throw $this->conflict('node.self_removal_forbidden', 'A node cannot remove itself.');
        }

        if ($node->roles()->where('role', RoleName::Gateway->value)->exists()) {
            throw $this->conflict('node.gateway_removal_forbidden', 'The gateway node cannot be removed.');
        }

        if ($node->roles()->where('role', RoleName::Vpn->value)->exists()) {
            throw $this->conflict('node.vpn_removal_forbidden', 'The VPN node cannot be removed.');
        }
    }

    /**
     * Sheds every role a proven-unreachable node still holds.
     *
     * This is what makes removal one command rather than two. The probe gates
     * the whole shed: a node that answers gets `null` back and falls through
     * to the ordinary `node.has_roles` refusal, so the claim never tears roles
     * off a node that is merely answering slowly.
     *
     * Each role then probes again on its own, inside `RemoveNodeRoleAction`.
     * That is deliberate -- liveness is re-checked immediately before every
     * destructive step rather than trusted from one reading -- but it means a
     * node that wakes mid-shed stops the removal partway: the roles already
     * shed stay shed and are reported, the waking role takes the ordinary
     * fail-closed path and lands in `Failed`, and the call fails. Retrying
     * once the node is properly up, or properly down, resolves it.
     *
     * @return list<string>|null the roles shed, or null when the node answered
     */
    private function shedRoles(Node $node, bool $force): ?array
    {
        if ($this->reachability->degradation($node) === null) {
            return null;
        }

        if (! $force) {
            throw new ResourceOperationException(
                'node.confirmation_required',
                "Removing unreachable node [{$node->name}] sheds its roles and their resources. Use --force.",
                422,
            );
        }

        $shed = [];

        foreach ($node->roles()->orderBy('role')->get() as $assignment) {
            $this->roles->execute($node, $assignment->role, force: true, purgeData: false, offline: true);
            $shed[] = $assignment->role->value;
        }

        return $shed;
    }

    private function guardRemoval(Node $node): void
    {
        if ($node->roles()->exists()) {
            throw $this->conflict(
                'node.has_roles',
                "Node [{$node->name}] still has roles. Remove them, or use --offline if the node is unreachable.",
            );
        }

        if ($node->instances()->exists()) {
            throw $this->conflict('node.has_instances', "Node [{$node->name}] still has instances.");
        }

        if ($node->firewallRules()->exists()) {
            throw $this->conflict('node.has_firewall_rules', "Node [{$node->name}] still has firewall rules.");
        }
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }

    private function restoreMetricsSelection(): ?Throwable
    {
        try {
            $this->metrics->reconcile();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function metricsRollbackFailure(Node $node, Throwable $previous): NodeRemovalException
    {
        return $this->failure(
            step: 'metrics-exporters-rollback',
            errorCode: 'node.removal_rollback_failed',
            message: "Could not restore Metrics exporter state for node [{$node->name}].",
            previous: $previous,
        );
    }

    private function failure(
        string $step,
        string $errorCode,
        string $message,
        Throwable $previous,
    ): NodeRemovalException {
        $result = $previous instanceof NodeProvisioningException ? $previous->result : null;

        return new NodeRemovalException($step, $errorCode, $message, $result, $previous);
    }
}
