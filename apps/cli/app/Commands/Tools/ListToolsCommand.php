<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Responses\Tools\ToolsResponse;

final class ListToolsCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:list {--node= : Numeric target node ID} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'List tools for a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $node = $this->nodeId();
        if ($node === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new ListToolsRequest($node), ToolsResponse::class);
        if (! $response instanceof ToolsResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        $rows = array_map(fn ($t): array => [
            $t->id,
            $t->manager,
            $t->package,
            $this->value($t->versionConstraint),
            $t->status,
            $this->value($t->installedVersion),
            $t->protected ? 'yes' : 'no',
            $this->value($t->errorCode),
        ], $response->tools);
        $this->table(['ID', 'Manager', 'Package', 'Constraint', 'Status', 'Version', 'Protected', 'Error'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
