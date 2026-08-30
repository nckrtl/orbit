<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsPublicationManager;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class MetricsRoleBaseline implements RoleBaseline
{
    public function __construct(
        private MetricsRuntimeLifecycle $runtime,
        private MetricsExporterLifecycle $exporters,
        private MetricsPublicationManager $publication,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $gateway = $this->gateway();
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

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $gateway = $this->gateway();
        $this->publication->remove($gateway, $node);
        $this->exporters->remove($node, $assignment);
        $this->runtime->remove($node, $assignment, $purgeData);
    }

    private function gateway(): Node
    {
        $gateways = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('roles', static fn ($query) => $query
                ->where('role', 'gateway')
                ->where('status', LifecycleStatus::Active->value))
            ->limit(2)
            ->get();

        if ($gateways->count() !== 1) {
            throw new ResourceOperationException(
                'metrics.gateway_ambiguous',
                'Metrics publication requires exactly one active Gateway.',
            );
        }

        return $gateways->sole();
    }
}
