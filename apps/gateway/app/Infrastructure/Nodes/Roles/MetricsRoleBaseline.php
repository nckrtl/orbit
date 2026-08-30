<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationManager;
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
     * fleet had lost the Gateway that publishes it. The Metrics node's own
     * runtime, exporters and firewall rules always come down; only the
     * Gateway-side publication is left behind, and the caller reports it.
     */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $gateway = $this->gateways->find();

        if ($gateway instanceof Node) {
            $this->publication->remove($gateway, $node);
        } else {
            $this->publication->abandon($node);
        }

        $this->exporters->remove($node, $assignment);
        $this->runtime->remove($node, $assignment, $purgeData);
    }
}
