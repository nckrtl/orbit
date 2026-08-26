<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\RemoveInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class RemoveInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:remove
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove an instance.';

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
            new RemoveInstanceRequest($instanceId),
            InstanceResponse::class,
        );

        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$instance->name}] removed.");
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
