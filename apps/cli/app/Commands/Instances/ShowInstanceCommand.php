<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class ShowInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:show
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show an instance.';

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

        $instance = $this->send($connector, new ShowAppInstanceRequest($instanceId), AppInstanceResponse::class);

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("{$instance->name} (#{$instance->id}): {$instance->status}");
        $this->line("App: {$instance->appId}");
        $this->line("Node: {$instance->nodeId}");
        $this->line("Environment: {$instance->environment}");
        $this->line("Source: {$instance->sourceKind}");
        $this->line("Checkout: {$instance->checkoutPath}");
        $this->line('Root override: '.($instance->root ?? '-'));
        $this->line('Effective root: '.($instance->effectiveRoot ?? '-'));
        $this->line('Branch: '.($instance->selectedBranch ?? '-'));
        $this->line('Starting commit: '.($instance->startingCommit ?? '-'));

        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
