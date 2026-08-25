<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class ShowInstanceCommand extends GatewayCommand
{
    protected $signature = 'instance:show
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    protected $description = 'Show an instance.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send($connector, new ShowInstanceRequest($instanceId));

        if (! $instance instanceof InstanceResponse) {
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
        $this->line("Checkout: {$instance->checkoutPath}");
        $this->line("Document root: {$instance->documentRoot}");
        $this->line("PHP: {$instance->phpVersion}");
        $this->line("Hostname: {$instance->hostname}");
        $this->line("Certificate: {$instance->certificateMode}");

        if ($instance->failedStep !== null || $instance->errorCode !== null) {
            $failure = implode(' / ', array_filter([$instance->failedStep, $instance->errorCode], is_string(...)));
            $this->line("Failure: {$failure}");
        }

        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }
}
