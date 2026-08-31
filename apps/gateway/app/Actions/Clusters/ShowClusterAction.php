<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Models\Cluster;

final readonly class ShowClusterAction
{
    public function handle(Cluster $cluster): Cluster
    {
        return $cluster->load(['nodes', 'routerAssignment.node']);
    }
}
