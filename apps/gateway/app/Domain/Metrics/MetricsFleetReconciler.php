<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsFleetReconciler
{
    public function reconcile(): void;

    public function retire(Node $node): void;
}
