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
    #[\Override]
    protected $signature = 'workspace:new
        {instance : Numeric instance ID}
        {name : Workspace name}
        {--branch= : Optional Git branch}
        {--path= : Absolute target-node checkout path; Linux default: /home/orbit/.orbit/worktrees/<app>/<workspace>}
        {--php= : Optional PHP major.minor override}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a workspace.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $name = $this->stringArgument('name', 'Workspace name', 'workspace.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $branch = $this->stringOption('branch');
        $checkoutPath = $this->stringOption('path');
        $phpVersion = $this->stringOption('php');

        if ($checkoutPath !== null && ! $this->isSafeCheckoutPath($checkoutPath)) {
            return $this->renderGatewayFailure(
                'workspace.checkout_path_invalid',
                'Workspace checkout path must be a safe child of /home/orbit.',
            );
        }

        if ($phpVersion !== null && ! $this->validPhpVersion($phpVersion)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $workspace = $this->send(
            $connector,
            new CreateWorkspaceRequest(
                instanceId: $instanceId,
                name: $name,
                branch: $branch,
                checkoutPath: $checkoutPath,
                phpVersion: $phpVersion,
            ),
            WorkspaceResponse::class,
        );

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
