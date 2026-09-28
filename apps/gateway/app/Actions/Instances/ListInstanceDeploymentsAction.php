<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListInstanceDeploymentsAction
{
    /** @return Collection<int, InstanceDeployment> */
    public function execute(Instance $instance): Collection
    {
        return $instance->deployments()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }
}
