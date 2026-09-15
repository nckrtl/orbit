<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
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
        $response = $this->sendWithProgress($connector, new ListRoutesRequest, RoutesResponse::class, ['List Routes', 'Loading Routes', 'Loaded Routes']);
        if (! $response instanceof RoutesResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        $rows = array_map(fn ($route): array => [
            $route->id,
            $route->domain,
            $route->provenance,
            $route->publication,
            $route->clusterId === null ? "node {$route->nodeId}" : "cluster {$route->clusterId}",
            $this->targetList($route),
            $route->status,
        ], $response->routes);
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'Domain', 'Provenance', 'Publication', 'Scope', 'Target', 'Status'], $rows, 'No Routes found.'));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
