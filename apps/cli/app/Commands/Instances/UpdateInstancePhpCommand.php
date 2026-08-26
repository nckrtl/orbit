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
    #[\Override]
    protected $signature = 'instance:php
        {instance : Numeric instance ID}
        {version : PHP major.minor version}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change an instance PHP version.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $phpVersion = $this->stringArgument('version', 'PHP version', 'php.version_required');

        if ($phpVersion === null) {
            return self::FAILURE;
        }

        if (! $this->validPhpVersion($phpVersion)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send(
            $connector,
            new UpdateInstancePhpRequest($instanceId, $phpVersion),
            InstanceResponse::class,
        );

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
