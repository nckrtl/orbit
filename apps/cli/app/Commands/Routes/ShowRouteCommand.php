<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class ShowRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:show {route : Numeric Route ID} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Show a Route.';

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
        $route = $this->send($connector, new ShowRouteRequest($id), RouteResponse::class);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "{$route->hostname} (#{$route->id})")
            : self::FAILURE;
    }
}
