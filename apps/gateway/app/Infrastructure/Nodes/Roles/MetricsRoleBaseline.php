<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Metrics\MetricsPublicationManager;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class MetricsRoleBaseline implements RoleBaseline
{
    public function __construct(
        private MetricsRuntimeLifecycle $runtime,
        private MetricsExporterLifecycle $exporters,
        private MetricsPublicationManager $publication,
        private MetricsGatewayResolver $gateways,
        private MetricsPublicationReport $report,
        private MetricsCadvisorLifecycle $cadvisors,
        private ?ServiceMetricsLifecycle $services = null,
    ) {}

    /**
     * Converges exporters, cAdvisor, the runtime, and the publication in that order. A failure names the step that
     * failed and its error code, so the assignment records `converge:metrics-<step>` instead of a generic baseline
     * failure. A failure before the runtime converged rolls back the exporters and cAdvisor first.
     */
    public function converge(Node $node, NodeRole $assignment): void
    {
        $gateway = $this->gateways->resolve();
        $exporters = false;
        $cadvisors = false;
        $runtime = false;
        $step = 'metrics-exporters';

        try {
            $this->exporters->converge($node, $assignment);
            $exporters = true;
            $step = 'metrics-cadvisor';
            $this->cadvisors->converge($node, $assignment);
            $cadvisors = true;
            $step = 'metrics-runtime';
            if ($this->services !== null) {
                $this->services->converge($node, fn () => $this->runtime->converge($node, $assignment));
            } else {
                $this->runtime->converge($node, $assignment);
            }
            $runtime = true;
            $step = 'metrics-publication';
            $this->publication->converge($gateway, $node);
        } catch (Throwable $exception) {
            if ($runtime) {
                throw $this->failure($node, $step, $exception);
            }

            try {
                if ($cadvisors) {
                    $this->cadvisors->remove($node, $assignment);
                }

                if ($exporters) {
                    $this->exporters->remove($node, $assignment);
                }
            } catch (Throwable $rollback) {
                throw new NodeRoleOperationException(
                    step: $step,
                    errorCode: 'node_role.convergence_failed',
                    underlyingErrorCode: 'metrics.rollback_failed',
                    message: 'Metrics convergence rollback failed.',
                    previous: new ResourceOperationException(
                        'metrics.convergence_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $this->failure($node, $step, $exception);
        }
    }

    /**
     * Keeps an exception that already names its step. Any other failure takes the Metrics step that raised it and
     * the Metrics error code when it has one. Only a Metrics error message is shown, because other messages can
     * carry command output.
     */
    private function failure(Node $node, string $step, Throwable $exception): Throwable
    {
        if (
            $exception instanceof NodeRoleOperationException
            || $exception instanceof RuntimeConvergenceException
            || $exception instanceof FirewallOperationException
            || $exception instanceof NodeProvisioningException
        ) {
            return $exception;
        }

        $known = $exception instanceof ResourceOperationException;

        return new NodeRoleOperationException(
            step: $step,
            errorCode: 'node_role.convergence_failed',
            underlyingErrorCode: $known ? $exception->errorCode : 'metrics.convergence_failed',
            message: $known ? $exception->getMessage() : "Metrics step [{$step}] failed on node [{$node->name}].",
            previous: $exception,
        );
    }

    /**
     * Removes the role, degrading when no single active Gateway is left.
     *
     * Demanding a Gateway here made the role unremovable exactly when the
     * fleet had lost the Gateway that publishes it.
     *
     * In the degraded branch the node's own state comes down first. The
     * Gateway-side publication is already lost either way, and abandoning the
     * firewall rule needs a live, single-ruled UFW on the Metrics node; a node
     * degraded enough to fail that would otherwise re-create the stuck role
     * this path exists to remove. A failed abandon is therefore folded into the
     * same un-cleaned report rather than aborting the removal.
     */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $gateway = $this->gateways->find();

        if ($gateway instanceof Node) {
            $this->publication->remove($gateway, $node);
            $this->removeAgents($node, $assignment);
            $this->runtime->remove($node, $assignment, $purgeData);
            $this->report->record(MetricsPublicationCleanup::Cleaned);

            return;
        }

        $this->removeAgents($node, $assignment);
        $this->runtime->remove($node, $assignment, $purgeData);

        try {
            $this->publication->abandon($node);
        } catch (Throwable) {
            // The report below already tells the operator the publication was
            // not cleaned, which is the whole signal a failure here would add.
        }

        $this->report->record(MetricsPublicationCleanup::Uncleaned);
    }

    /**
     * Removes the node exporters, cAdvisor, and service metrics that the fleet runs for this Metrics Node.
     *
     * When the Metrics role now runs on another Node, relocation has already re-pointed those fleet
     * agents at it, so they stay. Removing them here would stop every exporter the new Node scrapes.
     */
    private function removeAgents(Node $node, NodeRole $assignment): void
    {
        $relocated = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->where('node_id', '!=', $node->id)
            ->exists();

        if ($relocated) {
            return;
        }

        $this->exporters->remove($node, $assignment);
        $this->cadvisors->remove($node, $assignment);
        $this->services?->remove($node);
    }

    /**
     * Removes only what lives on the Gateway, for a Metrics node Orbit cannot reach.
     *
     * With a Gateway present, the route, the certificate and the DNS record
     * are all Gateway-local and are removed. The Metrics node's own firewall
     * rule, containers, volumes and `/etc/orbit/metrics` stay on the box,
     * since reaching them would require SSH to a node that is unreachable.
     *
     * With no single active Gateway, there is no Gateway-side state to
     * remove either, so nothing runs and the publication is reported
     * un-cleaned.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $gateway = $this->gateways->find();

        if ($gateway instanceof Node) {
            $this->publication->retract($node);
            $this->report->record(MetricsPublicationCleanup::Cleaned);

            return;
        }

        $this->report->record(MetricsPublicationCleanup::Uncleaned);
    }
}
