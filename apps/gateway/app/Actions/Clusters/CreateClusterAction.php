<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Data\Clusters\CreateClusterData;
use App\Domain\Clusters\ClusterState;
use App\Models\Cluster;

final readonly class CreateClusterAction
{
    public function execute(CreateClusterData $data): Cluster
    {
        return Cluster::query()->create([
            'name' => $data->name,
            'tld' => $data->tld,
            'state' => ClusterState::Inactive,
        ]);
    }
}
