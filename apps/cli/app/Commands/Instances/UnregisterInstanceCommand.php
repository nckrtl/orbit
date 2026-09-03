<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\UnregisterAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class UnregisterInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:unregister
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Unregister an externally owned AppInstance without changing its source.';

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
            new UnregisterAppInstanceRequest($instanceId),
            AppInstanceResponse::class,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$instance->name}] unregistered. External source was not changed.");
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
