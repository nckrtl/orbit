<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Routes\ListRoutesRequest;
use Orbit\Sdk\Responses\Routes\RoutesResponse;

final class ListRoutesCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List Routes.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new ListRoutesRequest, RoutesResponse::class);
        if (! $response instanceof RoutesResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        $rows = array_map(static fn ($route): array => [
            $route->id,
            $route->hostname,
            $route->provenance,
            $route->publication,
            $route->clusterId === null ? "node {$route->nodeId}" : "cluster {$route->clusterId}",
            $route->target->appInstanceId ?? '—',
            $route->status,
        ], $response->routes);
        $this->table(['ID', 'Hostname', 'Provenance', 'Publication', 'Scope', 'Target', 'Status'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
