<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class CreateInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:new
        {app : Numeric app ID}
        {node : Numeric node ID}
        {name : AppInstance name; default is reserved for the default development source}
        {--root= : Optional relative web-root override}
        {--hostname= : Optional explicit Route hostname}
        {--branch= : Optional explicit source branch}
        {--recover-source-profile : Adopt complete source evidence for a legacy incomplete checkpoint}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create an AppInstance on an app-dev or standalone app-prod node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->stringArgument('name', 'Instance name', 'instance.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send(
            $connector,
            new CreateAppInstanceRequest(
                appId: $appId,
                nodeId: $nodeId,
                name: $name,
                root: $this->stringOption('root'),
                hostname: $this->stringOption('hostname'),
                branch: $this->stringOption('branch'),
                recoverSourceProfile: $this->option('recover-source-profile') === true ? true : null,
            ),
            AppInstanceResponse::class,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$instance->name}] is {$instance->status}.");
        $this->line("Source layout: {$instance->sourceLayout}");
        if ($instance->productionUser !== null) {
            $this->line("Production user: {$instance->productionUser}");
            $this->line("Production home: {$instance->productionHome}");
        }
        $this->line('Effective root: '.($instance->effectiveRoot ?? '-'));
        $this->line('Selected branch: '.($instance->selectedBranch ?? '-'));
        $this->line('Branch override: '.($instance->branchOverride ?? '-'));
        $this->line('Migration required: '.($instance->migrationRequired ? 'yes' : 'no'));
        if ($instance->url !== null) {
            $this->line('Route hostname: '.($instance->hostname ?? '-'));
            $this->line("URL: {$instance->url}");
        }
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
