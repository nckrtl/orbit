<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Models\Node;

/**
 * Decides whether a node is genuinely unreachable before Orbit skips work on it.
 *
 * Removal and role teardown are fail-closed: a step that fails leaves the
 * assignment in `Failed` and changes nothing. That is right for a node that
 * answers and misbehaves, and wrong for a node that is simply gone. The probe
 * is the one place that separates the two, and it runs *before* any teardown,
 * so a skipped step is never a swallowed failure.
 *
 * The verdict reuses {@see ExporterDegradationReason}, the vocabulary the
 * Metrics fleet already degrades with, so an operator reads one word for one
 * condition across the whole product.
 */
interface NodeReachabilityProbe
{
    /**
     * The degradation that justifies skipping node-side teardown.
     *
     * `null` means the node answered: every teardown step still has to run,
     * and a failure in any of them still fails closed.
     */
    public function degradation(Node $node): ?ExporterDegradationReason;
}
