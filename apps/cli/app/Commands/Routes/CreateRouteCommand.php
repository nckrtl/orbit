<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class CreateRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:new
        {app : Numeric App ID}
        {hostname : Route hostname}
        {--publication=private : Publication intent}
        {--target= : Numeric AppInstance target ID}
        {--node= : Numeric Node scope ID for a targetless Route}
        {--cluster= : Numeric Cluster scope ID for a targetless Route}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create an explicit Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');
        if ($appId === null) {
            return self::FAILURE;
        }

        $hostname = $this->stringArgument('hostname', 'Route hostname', 'route.hostname_required');
        if ($hostname === null) {
            return self::FAILURE;
        }

        $targetId = $this->optionId('target', 'AppInstance');
        if ($targetId === 0) {
            return self::FAILURE;
        }

        $nodeId = $this->optionId('node', 'Node');
        if ($nodeId === 0) {
            return self::FAILURE;
        }

        $clusterId = $this->optionId('cluster', 'Cluster');
        if ($clusterId === 0) {
            return self::FAILURE;
        }

        $publication = $this->option('publication');
        if (! is_string($publication)) {
            return self::FAILURE;
        }

        if ($targetId !== null && ($nodeId !== null || $clusterId !== null)) {
            return $this->renderGatewayFailure('route.scope_conflict', 'Do not combine a target with Route scope.');
        }

        if ($targetId === null && ($nodeId === null) === ($clusterId === null)) {
            return $this->renderGatewayFailure(
                'route.scope_required',
                'A targetless Route requires exactly one Node or Cluster scope.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $route = $this->send(
            $connector,
            new CreateRouteRequest(
                appId: $appId,
                hostname: $hostname,
                publication: $publication,
                appInstanceId: $targetId,
                nodeId: $nodeId,
                clusterId: $clusterId,
            ),
            RouteResponse::class,
        );

        return $route instanceof RouteResponse
            ? $this->renderRoute($route, "Route [{$route->hostname}] created.")
            : self::FAILURE;
    }
}
