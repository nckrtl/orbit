<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\RouteData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class UpdateRouteAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private ConvergeRouteAction $converge,
        private PublishPublicRouteAction $publishPublic,
        private RouteReconciliationGuard $reconciliation,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
    ) {}

    public function execute(Route $route, UpdateRouteData $data): Route
    {
        if (! $route->isApp()) {
            throw new ResourceOperationException(
                errorCode: 'route.kind_unsupported',
                message: 'Only an App Route can change domain or publication through Route update.',
                status: 409,
            );
        }

        /** @var list<int> $targetIds */
        $targetIds = $route
            ->targets()
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $result = $this->environmentOperations->run(
            $targetIds,
            fn (): Route => $this->executeOwned($route, $data, $targetIds),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::RouteUpdated,
            $result->id,
            RouteData::fromModel($result)->toArray(),
        );

        $this->metrics?->reconcile();

        return $result;
    }

    /** @param list<int> $expectedTargetIds */
    private function executeOwned(Route $route, UpdateRouteData $data, array $expectedTargetIds): Route
    {
        $route->refresh()->load('targets');
        $currentTargetIds = $route
            ->targets
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($currentTargetIds !== $expectedTargetIds) {
            throw new ResourceOperationException(
                errorCode: 'env.owner_changed',
                message: 'The AppInstance environment owner changed during the operation.',
                status: 409,
            );
        }
        $domain = $data->domainProvided && $data->domain !== null
            ? RouteDomain::validate($data->domain)
            : null;
        $publicationChanges =
            $data->publicationProvided && $data->publication !== null && $route->publication !== $data->publication;

        $requestedPublication = $data->publicationProvided ? $data->publication : null;
        $domainChanges = $domain !== null && $domain !== $route->domain;

        if (
            $domainChanges
            && in_array($route->status, [
                RouteStatus::Active,
                RouteStatus::Activating,
                RouteStatus::Retiring,
                RouteStatus::Failed,
            ], true)
        ) {
            return $this->converge->execute($route, $domain, $requestedPublication);
        }

        if (
            ! $domainChanges
            && $requestedPublication instanceof RoutePublication
            && in_array($route->status, [RouteStatus::Active, RouteStatus::Activating], true)
        ) {
            return $this->publishPublic->execute($route, $requestedPublication);
        }

        if ($route->status === RouteStatus::Pending && $domain !== null && $domain !== $route->domain) {
            return $this->replacePending($route, $domain);
        }

        /** @var Route $updated */
        $updated = DB::transaction(function () use ($route, $data): Route {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $attributes = [];

            if ($data->domainProvided) {
                if ($locked->provenance !== RouteProvenance::Explicit || $data->domain === null) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_immutable',
                        message: 'Only an explicit Route domain can be updated.',
                        status: 409,
                    );
                }

                if ($data->domain !== $locked->domain) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_immutable',
                        message: 'A Route domain change must create a replacement Route.',
                        status: 409,
                    );
                }
            }

            if ($data->publicationProvided && $data->publication !== null) {
                $attributes['publication'] = $data->publication;
            }

            $changed = array_filter(
                $attributes,
                static fn (RoutePublication $value): bool => $locked->publication !== $value,
            );

            if ($changed !== []) {
                $this->reconciliation->assertRouteMutable($locked);
            }

            $locked->update($attributes);

            return $locked->refresh()->load('targets');
        });

        return $updated;
    }

    private function replacePending(Route $route, string $domain): Route
    {
        /** @var Route $replacement */
        $replacement = DB::transaction(function () use ($route, $domain): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);

            if ($locked->provenance !== RouteProvenance::Explicit) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_immutable',
                    message: 'Only an explicit Route domain can be updated.',
                    status: 409,
                );
            }

            $this->reconciliation->assertRouteMutable($locked);

            $occupied = Route::query()
                ->whereKeyNot($locked->id)
                ->where('domain', $domain)
                ->exists();

            if ($occupied) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: 'The Route domain is already owned.',
                    status: 409,
                );
            }

            $created = Route::query()->create([
                'app_id' => $locked->app_id,
                'node_id' => $locked->node_id,
                'cluster_id' => $locked->cluster_id,
                'generation_basis_node_id' => $locked->generation_basis_node_id,
                'domain' => $domain,
                'provenance' => $locked->provenance,
                'publication' => $locked->publication,
                'status' => RouteStatus::Pending,
                'replaces_route_id' => $locked->id,
                'replacement_step' => RouteReplacementStep::Reserved,
            ]);

            foreach ($locked->targets as $target) {
                $created->targets()->create([
                    'app_instance_id' => $target->app_instance_id,
                    'position' => $target->position,
                ]);
            }

            $locked->targets()->delete();
            $locked->delete();
            $created->update([
                'replaces_route_id' => null,
                'replacement_step' => null,
            ]);

            return $created->refresh()->load('targets');
        });

        return $replacement;
    }
}
