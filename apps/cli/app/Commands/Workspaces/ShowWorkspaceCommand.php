<?php

declare(strict_types=1);

namespace App\Commands\Workspaces;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Workspaces\ShowWorkspaceRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

final class ShowWorkspaceCommand extends GatewayCommand
{
    protected $signature = 'workspace:show
        {workspace : Numeric workspace ID}
        {--json : Return machine-readable JSON}';

    protected $description = 'Show a workspace.';

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

        $workspace = $this->send($connector, new ShowWorkspaceRequest($workspaceId));

        if (! $workspace instanceof WorkspaceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($workspace->toArray());

            return self::SUCCESS;
        }

        $this->info("{$workspace->name} (#{$workspace->id}): {$workspace->status}");
        $this->line("Instance: {$workspace->instanceId}");
        $this->line("Node: {$workspace->nodeId}");
        $this->line("Branch: {$workspace->branch}");
        $this->line("Checkout: {$workspace->checkoutPath}");
        $this->line("PHP: {$workspace->effectivePhpVersion}");
        $this->line("Hostname: {$workspace->hostname}");

        if ($workspace->failedStep !== null || $workspace->errorCode !== null) {
            $failure = implode(' / ', array_filter([$workspace->failedStep, $workspace->errorCode], is_string(...)));
            $this->line("Failure: {$failure}");
        }

        $this->line("Request ID: {$workspace->requestId}");

        return self::SUCCESS;
    }
}
