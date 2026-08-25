<?php

declare(strict_types=1);

namespace App\Commands\Workspaces;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Workspaces\RemoveWorkspaceRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

final class RemoveWorkspaceCommand extends GatewayCommand
{
    protected $signature = 'workspace:remove
        {workspace : Numeric workspace ID}
        {--json : Return machine-readable JSON}';

    protected $description = 'Remove a workspace.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $workspaceId = $this->positiveId('workspace', 'Workspace');

        if ($workspaceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $workspace = $this->send($connector, new RemoveWorkspaceRequest($workspaceId));

        if (! $workspace instanceof WorkspaceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($workspace->toArray());

            return self::SUCCESS;
        }

        $this->info("Workspace [{$workspace->name}] removed.");
        $this->line("Request ID: {$workspace->requestId}");

        return self::SUCCESS;
    }
}
