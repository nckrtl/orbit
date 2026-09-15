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
        {--domain= : New explicit domain}
        {--publication= : New publication intent}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $id = $this->routeId();
        if ($id === null) {
            return self::FAILURE;
        }
        $domain = $this->stringOption('domain');
        $publication = null;
        if ($this->input->hasParameterOption('--publication')) {
            $publication = $this->publication($this->option('publication'));
            if ($publication === null) {
                return self::FAILURE;
            }
        }
        if ($domain === null && $publication === null) {
            return $this->renderGatewayFailure('route.update_required', 'Provide at least one Route update.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $route = $this->sendWithProgress($connector, new UpdateRouteRequest($id, domain: $domain, publication: $publication), RouteResponse::class, ['Update Route', 'Updating Route', 'Updated Route']);

        return $route instanceof RouteResponse
            ? $this->renderRoute($route)
            : self::FAILURE;
    }
}
