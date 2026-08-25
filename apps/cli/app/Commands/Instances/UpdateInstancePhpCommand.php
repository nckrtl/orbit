<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\UpdateInstancePhpRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class UpdateInstancePhpCommand extends GatewayCommand
{
    protected $signature = 'instance:php
        {instance : Numeric instance ID}
        {version : PHP major.minor version}
        {--json : Return machine-readable JSON}';

    protected $description = 'Change an instance PHP version.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance');
        $phpVersion = $this->stringArgument('version', 'PHP version');

        if ($instanceId === null || $phpVersion === null) {
            return self::FAILURE;
        }

        if (! $this->validPhpVersion($phpVersion)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send($connector, new UpdateInstancePhpRequest($instanceId, $phpVersion));

        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$instance->name}] now uses PHP {$instance->phpVersion}.");
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
