<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsPublicationManager
{
    public function converge(Node $gateway, Node $metrics): void;

    public function remove(Node $gateway, Node $metrics): void;
}
