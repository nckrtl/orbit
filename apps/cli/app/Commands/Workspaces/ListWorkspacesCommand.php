<?php

declare(strict_types=1);

namespace App\Commands\Workspaces;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Workspaces\ListWorkspacesRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspacesResponse;

final class ListWorkspacesCommand extends GatewayCommand
{
    protected $signature = 'workspace:list
        {--json : Return machine-readable JSON}';

    protected $description = 'List workspaces.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListWorkspacesRequest);

        if (! $response instanceof WorkspacesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->workspaces as $workspace) {
            $rows[] = [
                $workspace->id,
                $workspace->instanceId,
                $workspace->nodeId,
                $workspace->name,
                $workspace->branch,
                $workspace->status,
                $workspace->effectivePhpVersion,
                $workspace->hostname,
            ];
        }

        $this->table(['ID', 'Instance', 'Node', 'Name', 'Branch', 'Status', 'PHP', 'Hostname'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
