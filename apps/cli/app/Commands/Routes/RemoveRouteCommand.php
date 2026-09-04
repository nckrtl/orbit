<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\RemoveRouteRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class RemoveRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:remove {route : Numeric Route ID} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Remove a Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->routeId();
        if ($id === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $route = $this->send($connector, new RemoveRouteRequest($id), RouteResponse::class);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "Route [{$route->hostname}] removed.")
            : self::FAILURE;
    }
}
