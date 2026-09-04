<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class SetRouteTargetCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:target:set
        {route : Numeric Route ID}
        {target : Numeric AppInstance target ID}
        {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Set the single configured Route target.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $routeId = $this->routeId();
        $targetId = $this->positiveId('target', 'AppInstance', 'route.target_id_invalid');
        if ($routeId === null || $targetId === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $route = $this->send($connector, new SetRouteTargetRequest($routeId, $targetId), RouteResponse::class);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "Route [{$route->hostname}] target updated.")
            : self::FAILURE;
    }
}
