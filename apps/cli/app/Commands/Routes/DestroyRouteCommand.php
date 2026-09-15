<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class DestroyRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:destroy {route : Numeric Route ID} {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

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
        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress(
                $connector,
                new ShowRouteRequest($id),
                RouteResponse::class,
                ['Inspect Route', 'Inspecting Route', 'Inspected Route'],
            );

            if (! $existing instanceof RouteResponse || ! $this->confirmAction(
                "Confirm Route removal [{$existing->domain}]?",
                'Route removal cancelled.',
            )) {
                return self::FAILURE;
            }
        }

        $route = $this->sendWithProgress($connector, new DestroyRouteRequest($id), RouteResponse::class, ['Remove Route', 'Removing Route', 'Removed Route']);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route)
            : self::FAILURE;
    }
}
