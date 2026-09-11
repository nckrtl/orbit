<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\AppInstanceDeploymentLayoutRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class PrepareDeploymentCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:prepare-deployment
        {instance : Numeric instance ID}
        {--sqlite-source-path= : Optional existing SQLite database to move outside releases}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Convert an existing production AppInstance to the release deployment layout.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send(
            $connector,
            new AppInstanceDeploymentLayoutRequest(
                appInstanceId: $instanceId,
                sqliteSourcePath: $this->stringOption('sqlite-source-path'),
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

        $this->info("Deployment layout prepared for instance [{$instance->name}].");
        $this->line("Source layout: {$instance->sourceLayout}");
        $this->line("Checkout: {$instance->checkoutPath}");
        $this->line('Effective root: '.($instance->effectiveRoot ?? '-'));
        $this->line('Route hostname: '.($instance->hostname ?? '-'));
        $this->line('URL: '.($instance->url ?? '-'));
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
