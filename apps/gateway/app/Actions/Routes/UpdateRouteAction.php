<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\UpdateRouteData;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateRouteAction
{
    public function execute(Route $route, UpdateRouteData $data): Route
    {
        try {
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

                $locked->update($attributes);

                return $locked->refresh()->load('targets');
            });

            return $updated;
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                errorCode: 'route.hostname_conflict',
                message: 'The Route hostname is already owned.',
                status: 409,
                previous: $exception,
            );
        }
    }
}
