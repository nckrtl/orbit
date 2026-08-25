<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class CreateInstanceCommand extends GatewayCommand
{
    protected $signature = 'instance:new
        {app : Numeric app ID}
        {node : Numeric node ID}
        {name : Metadata name; source path and hostname use the app slug}
        {--environment=development : Environment name}
        {--document-root=public : Web document root relative to the checkout}
        {--php=8.5 : PHP major.minor version}
        {--json : Return machine-readable JSON}';

    protected $description = 'Create the single instance of an app on a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App');
        $nodeId = $this->positiveId('node', 'Node');
        $name = $this->stringArgument('name', 'Instance name');
        $environment = $this->stringOption('environment');
        $documentRoot = $this->stringOption('document-root');
        $phpVersion = $this->stringOption('php');

        if ($appId === null || $nodeId === null || $name === null) {
            return self::FAILURE;
        }

        if ($environment === null || $documentRoot === null || $phpVersion === null) {
            $this->error('Environment, document root, and PHP version are required.');

            return self::FAILURE;
        }

        if (! $this->validPhpVersion($phpVersion)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send($connector, new CreateInstanceRequest(
            appId: $appId,
            nodeId: $nodeId,
            name: $name,
            environment: $environment,
            documentRoot: $documentRoot,
            phpVersion: $phpVersion,
        ));

        if (! $instance instanceof InstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$instance->name}] is {$instance->status}.");
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
