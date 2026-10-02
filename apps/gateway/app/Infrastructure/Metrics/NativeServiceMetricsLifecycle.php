<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsResourceFailure;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Closure;
use Throwable;

final readonly class NativeServiceMetricsLifecycle implements ServiceMetricsLifecycle
{
    public function __construct(
        private ServiceMetricsProjection $projection,
        private ServiceMetricsRuntime $runtime,
        private ExporterDegradationRepository $degradations,
    ) {}

    public function converge(Node $metricsNode, ?Closure $publish = null): void
    {
        $this->mutate($metricsNode, $this->projection->forFleet($metricsNode), $publish);
    }

    public function remove(Node $metricsNode): void
    {
        $this->mutate($metricsNode, $this->projection->forFleet($metricsNode, false));
    }

    public function removeNode(Node $node, Node $metricsNode): void
    {
        $this->mutate($metricsNode, [$this->projection->forNode($metricsNode, $node, false)]);
    }

    /** @param list<ServiceMetricsNode> $targets */
    private function mutate(Node $metricsNode, array $targets, ?Closure $publish = null): void
    {
        $snapshots = [];
        foreach ($targets as $target) {
            if ($this->degradations->hasExporterDegradation($target->node->id)) {
                continue;
            }
            try {
                $snapshots[] = [$target, $this->runtime->snapshot($target)];
            } catch (Throwable $failure) {
                if (($lockLoss = NodeLockLoss::keep($failure)) !== null) {
                    throw $lockLoss;
                }
                $this->degrade($target, 'snapshot', $failure);
            }
        }
        $changed = [];
        try {
            foreach ($snapshots as [$target, $snapshot]) {
                try {
                    $this->runtime->converge($target, $metricsNode);
                } catch (Throwable $failure) {
                    if (($lockLoss = NodeLockLoss::keep($failure)) !== null) {
                        throw $lockLoss;
                    }
                    try {
                        // Convergence may have changed this Node before it failed.
                        $this->runtime->restore($target, $snapshot);
                    } catch (Throwable $rollback) {
                        if (($lockLoss = NodeLockLoss::keep($rollback)) !== null) {
                            throw $lockLoss;
                        }
                        $this->degrade($target, 'restore', new ResourceOperationException(
                            'metrics.service_rollback_failed',
                            'Service metrics recovery did not complete.',
                            502,
                            $rollback,
                        ));

                        continue;
                    }
                    $this->degrade($target, 'converge', $failure);

                    continue;
                }
                $changed[] = [$target, $snapshot];
            }
            $publish?->__invoke();
            $this->degradations->forgetServiceFailures(array_map(
                static fn (array $change): int => $change[0]->node->id,
                $changed,
            ));
        } catch (Throwable $failure) {
            if (($lockLoss = NodeLockLoss::keep($failure)) !== null) {
                throw $lockLoss;
            }
            $rollbackFailure = null;
            foreach (array_reverse($changed) as [$target, $snapshot]) {
                try {
                    $this->runtime->restore($target, $snapshot);
                } catch (Throwable $rollback) {
                    if (($lockLoss = NodeLockLoss::keep($rollback)) !== null) {
                        throw $lockLoss;
                    }
                    $rollbackFailure ??= $rollback;
                }
            }
            if ($rollbackFailure !== null) {
                throw new ResourceOperationException('metrics.service_rollback_failed', 'Service metrics recovery did not complete.', 502, $rollbackFailure);
            }
            throw $failure;
        }
    }

    private function degrade(ServiceMetricsNode $target, string $step, Throwable $failure): void
    {
        $structured = MetricsResourceFailure::find($failure);
        $this->degradations->recordReconcileFailure(
            $target->node->id,
            $step,
            $structured->errorCode ?? 'metrics.service_reconcile_failed',
        );
    }
}
