<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Metrics\MetricsPublicationManager;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class MetricsRoleBaseline implements RoleBaseline
{
    public function __construct(
        private MetricsRuntimeLifecycle $runtime,
        private MetricsExporterLifecycle $exporters,
        private MetricsPublicationManager $publication,
        private MetricsGatewayResolver $gateways,
        private MetricsPublicationReport $report,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $gateway = $this->gateways->resolve();
        $exporters = false;
        $runtime = false;
        $publication = false;

        try {
            $this->exporters->converge($node, $assignment);
            $exporters = true;
            $this->runtime->converge($node, $assignment);
            $runtime = true;
            $this->publication->converge($gateway, $node);
            $publication = true;
        } catch (\Throwable $exception) {
            try {
                if ($publication) {
                    $this->publication->remove($gateway, $node);
                }

                if ($runtime) {
                    $this->runtime->remove($node, $assignment, false);
                }

                if ($exporters) {
                    $this->exporters->remove($node, $assignment);
                }
            } catch (\Throwable $rollback) {
                throw new ResourceOperationException(
                    'metrics.rollback_failed',
                    'Metrics convergence rollback failed.',
                    502,
                    new ResourceOperationException(
                        'metrics.convergence_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $exception;
        }
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
            $this->exporters->remove($node, $assignment);
            $this->runtime->remove($node, $assignment, $purgeData);
            $this->report->record(MetricsPublicationCleanup::Cleaned);

            return;
        }

        $this->exporters->remove($node, $assignment);
        $this->runtime->remove($node, $assignment, $purgeData);

        try {
            $this->publication->abandon($node);
        } catch (\Throwable) {
            // The report below already tells the operator the publication was
            // not cleaned, which is the whole signal a failure here would add.
        }

        $this->report->record(MetricsPublicationCleanup::Uncleaned);
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
