<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;
use App\Models\NodeRole;

interface MetricsRuntimeLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void;

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void;

    public function health(Node $node, string $service): bool;
}
