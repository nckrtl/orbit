<?php

declare(strict_types=1);

namespace App\Commands\Workspaces;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Workspaces\CreateWorkspaceRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

final class CreateWorkspaceCommand extends GatewayCommand
{
    protected $signature = 'workspace:new
        {instance : Numeric instance ID}
        {name : Workspace name}
        {--branch= : Git branch, defaults to the workspace name}
        {--path= : Absolute workspace checkout path}
        {--php= : Optional PHP major.minor override}
        {--json : Return machine-readable JSON}';

    protected $description = 'Create a workspace.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance');
        $name = $this->stringArgument('name', 'Workspace name');

        if ($instanceId === null || $name === null) {
            return self::FAILURE;
        }

        $branch = $this->stringOption('branch') ?? $name;
        $checkoutPath = $this->stringOption('path');
        $phpVersion = $this->stringOption('php');

        if ($checkoutPath !== null && ! $this->isSafeCheckoutPath($checkoutPath)) {
            $this->error('Workspace checkout path must be a safe child of /home/orbit.');

            return self::FAILURE;
        }

        if ($phpVersion !== null && ! $this->validPhpVersion($phpVersion)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $workspace = $this->send($connector, new CreateWorkspaceRequest(
            instanceId: $instanceId,
            name: $name,
            branch: $branch,
            checkoutPath: $checkoutPath,
            phpVersion: $phpVersion,
        ));

        if (! $workspace instanceof WorkspaceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($workspace->toArray());

            return self::SUCCESS;
        }

        $this->info("Workspace [{$workspace->name}] is {$workspace->status}.");
        $this->line("Request ID: {$workspace->requestId}");

        return self::SUCCESS;
    }

    private function isSafeCheckoutPath(string $path): bool
    {
        return (
            preg_match(
                '/\A\/home\/orbit\/(?!\.{1,2}(?:\/|\z))[A-Za-z0-9._-]+(?:\/(?!\.{1,2}(?:\/|\z))[A-Za-z0-9._-]+)*\z/D',
                $path,
            ) === 1
        );
    }
}
