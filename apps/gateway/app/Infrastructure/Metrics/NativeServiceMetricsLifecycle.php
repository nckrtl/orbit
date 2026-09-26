<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterDegradationRepository;
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
            if ($this->degradations->get($target->node->id) !== null) {
                continue;
            }
            $snapshots[] = [$target, $this->runtime->snapshot($target)];
        }
        $changed = [];
        try {
            foreach ($snapshots as [$target, $snapshot]) {
                $changed[] = [$target, $snapshot];
                $this->runtime->converge($target, $metricsNode);
            }
            $publish?->__invoke();
        } catch (Throwable $failure) {
            $rollbackFailure = null;
            foreach (array_reverse($changed) as [$target, $snapshot]) {
                try {
                    $this->runtime->restore($target, $snapshot);
                } catch (Throwable $rollback) {
                    $rollbackFailure ??= $rollback;
                }
            }
            // A lost Node lock refuses the rollback's commands too; the loss is the failure to report.
            if ($rollbackFailure !== null && ! NodeLockLoss::in($failure)) {
                throw new ResourceOperationException('metrics.service_rollback_failed', 'Service metrics recovery did not complete.', 502, $rollbackFailure);
            }
            throw $failure;
        }
    }
}
