<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Models\AppInstanceDeployment;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListAppInstanceDeploymentsAction
{
    /** @return Collection<int, AppInstanceDeployment> */
    public function execute(Instance $instance): Collection
    {
        return $instance->deployments()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }
}
