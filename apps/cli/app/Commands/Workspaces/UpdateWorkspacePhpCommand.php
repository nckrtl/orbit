<?php

declare(strict_types=1);

namespace App\Commands\Workspaces;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Workspaces\UpdateWorkspacePhpRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

final class UpdateWorkspacePhpCommand extends GatewayCommand
{
    protected $signature = 'workspace:php
        {workspace : Numeric workspace ID}
        {version : PHP major.minor version}
        {--json : Return machine-readable JSON}';

    protected $description = 'Change a workspace PHP version.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $workspaceId = $this->positiveId('workspace', 'Workspace');
        $phpVersion = $this->stringArgument('version', 'PHP version');

        if ($workspaceId === null || $phpVersion === null) {
            return self::FAILURE;
        }

        if (! $this->validPhpVersion($phpVersion)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $workspace = $this->send($connector, new UpdateWorkspacePhpRequest($workspaceId, $phpVersion));

        if (! $workspace instanceof WorkspaceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($workspace->toArray());

            return self::SUCCESS;
        }

        $this->info("Workspace [{$workspace->name}] now uses PHP {$workspace->phpVersion}.");
        $this->line("Request ID: {$workspace->requestId}");

        return self::SUCCESS;
    }
}
