<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\UpdateRouteData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:cyclomatic-complexity The action preserves validation, active convergence, and pending mutation order. */
final readonly class UpdateRouteAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private ConvergeRouteAction $converge,
        private RouteReconciliationGuard $reconciliation,
    ) {}

    public function execute(Route $route, UpdateRouteData $data): Route
    {
        /** @var list<int> $targetIds */
        $targetIds = $route
            ->targets()
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $this->environmentOperations->run(
            $targetIds,
            fn (): Route => $this->executeOwned($route, $data, $targetIds),
        );
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
        $hostname = $data->hostnameProvided && $data->hostname !== null
            ? RouteHostname::validate($data->hostname)
            : null;
        $publicationChanges =
            $data->publicationProvided && $data->publication !== null && $route->publication !== $data->publication;

        if ($route->status === \App\Domain\Routes\RouteStatus::Active && $publicationChanges) {
            $this->reconciliation->refuse();
        }

        if ($route->status === \App\Domain\Routes\RouteStatus::Active && $hostname !== null) {
            return $this->converge->execute($route, $hostname);
        }

        /** @var Route $updated */
        $updated = DB::transaction(function () use ($route, $data): Route {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $attributes = [];

            if ($data->hostnameProvided) {
                if ($locked->provenance !== RouteProvenance::Explicit || $data->hostname === null) {
                    throw new ResourceOperationException(
                        errorCode: 'route.hostname_immutable',
                        message: 'Only an explicit Route hostname can be updated.',
                        status: 409,
                    );
                }

                $attributes['hostname'] = RouteHostname::validate($data->hostname);
            }

            if ($data->publicationProvided && $data->publication !== null) {
                $attributes['publication'] = $data->publication;
            }

            $changed = array_filter(
                $attributes,
                static function (mixed $value, string $key) use ($locked): bool {
                    $current = $locked->getAttribute($key);

                    return (
                        ($current instanceof \BackedEnum ? $current->value : $current)
                        !== ($value instanceof \BackedEnum ? $value->value : $value)
                    );
                },
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed !== []) {
                $this->reconciliation->assertRouteMutable($locked);
            }

            try {
                $locked->update($attributes);
            } catch (QueryException $exception) {
                if (! array_key_exists('hostname', $changed)) {
                    throw $exception;
                }

                throw new ResourceOperationException(
                    errorCode: 'route.hostname_conflict',
                    message: 'The Route hostname is already owned.',
                    status: 409,
                    previous: $exception,
                );
            }

            return $locked->refresh()->load('targets');
        });

        return $updated;
    }
}
