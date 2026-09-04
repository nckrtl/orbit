<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class UpdateRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:update
        {route : Numeric Route ID}
        {--hostname= : New explicit hostname}
        {--publication= : New publication intent}
        {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Update a Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->routeId();
        $hostname = $this->stringOption('hostname');
        $publication = $this->stringOption('publication');
        if ($id === null) {
            return self::FAILURE;
        }
        if ($hostname === null && $publication === null) {
            return $this->renderGatewayFailure('route.update_required', 'Provide at least one Route update.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $route = $this->send($connector, new UpdateRouteRequest($id, $hostname, $publication), RouteResponse::class);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "Route [{$route->hostname}] updated.")
            : self::FAILURE;
    }
}
