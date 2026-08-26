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
    #[\Override]
    protected $signature = 'instance:new
        {app : Numeric app ID}
        {node : Numeric node ID}
        {name : Metadata name; source path and hostname use the app slug}
        {--environment= : Environment name; the gateway derives the role default when omitted}
        {--hostname= : Public hostname required for app-prod}
        {--document-root=public : Web document root relative to the checkout}
        {--php=8.5 : PHP major.minor version}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create the single instance of an app on a node.';

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

        $environment = $this->stringOption('environment');
        $hostname = $this->stringOption('hostname');
        $documentRoot = $this->stringOption('document-root');
        $phpVersion = $this->stringOption('php');

        if ($documentRoot === null || $phpVersion === null) {
            return $this->renderGatewayFailure(
                'instance.options_required',
                'Document root and PHP version are required.',
            );
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
            new CreateInstanceRequest(
                appId: $appId,
                nodeId: $nodeId,
                name: $name,
                environment: $environment,
                documentRoot: $documentRoot,
                phpVersion: $phpVersion,
                hostname: $hostname,
            ),
            InstanceResponse::class,
        );

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
