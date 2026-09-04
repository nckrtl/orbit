<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Data\Clusters\UpdateClusterData;
use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Clusters\ClusterState;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use Illuminate\Support\Facades\DB;

final readonly class UpdateClusterAction
{
    public function __construct(
        private ActiveTldScopeGuard $tldScope,
        private ?RouteMutationReconciler $routes = null,
    ) {}

    public function execute(Cluster $cluster, UpdateClusterData $data): Cluster
    {
        /**
         * @var Cluster $updated
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's transaction result.
         */
        $updated = DB::transaction(function () use ($cluster, $data): Cluster {
            $locked = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $proposedTld = $data->tldProvided ? $data->tld : $locked->tld;
            $proposedState = $data->state ?? $locked->state;

            $this->tldScope->assertClusterTldAvailable($locked, $proposedTld, $proposedState);

            if ($proposedState === ClusterState::Active && $proposedTld !== null) {
                $hasActiveRouter = $locked
                    ->routerAssignment()
                    ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))
                    ->exists();

                if (! $hasActiveRouter) {
                    throw new ResourceOperationException(
                        errorCode: 'cluster.router_required',
                        message: "Cluster [{$locked->name}] requires one active Router.",
                        status: 409,
                    );
                }
            }

            $updates = [];

            if ($data->nameProvided) {
                $updates['name'] = $data->name;
            }

            if ($data->tldProvided) {
                $updates['tld'] = $data->tld;
            }

            if ($data->stateProvided) {
                $updates['state'] = $data->state;
            }

            ($this->routes ?? app(RouteMutationReconciler::class))->reconcile(clusterOverrides: [
                $locked->id => ['tld' => $proposedTld, 'state' => $proposedState],
            ]);

            $locked->update($updates);

            return $locked->refresh();
        });

        return $updated;
    }
}
