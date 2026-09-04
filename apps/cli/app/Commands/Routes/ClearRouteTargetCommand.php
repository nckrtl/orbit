<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\ClearRouteTargetRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class ClearRouteTargetCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:target:clear {route : Numeric Route ID} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Clear the configured Route target.';

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
        $route = $this->send($connector, new ClearRouteTargetRequest($id), RouteResponse::class);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "Route [{$route->hostname}] target cleared.")
            : self::FAILURE;
    }
}
