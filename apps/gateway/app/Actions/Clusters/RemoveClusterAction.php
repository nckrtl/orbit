<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use Illuminate\Support\Facades\DB;

final readonly class RemoveClusterAction
{
    public function __construct(
        private ?RouteRemovalGuard $routes = null,
    ) {}

    public function execute(Cluster $cluster): Cluster
    {
        /**
         * @var Cluster $removed
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's transaction result.
         */
        $removed = DB::transaction(function () use ($cluster): Cluster {
            $locked = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
            ($this->routes ?? app(RouteRemovalGuard::class))->assertClusterRemovable($locked);

            if ($locked->nodes()->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.not_empty',
                    message: "Cluster [{$locked->name}] still has Nodes.",
                    status: 409,
                );
            }

            $locked->delete();

            return $locked;
        });

        return $removed;
    }
}
