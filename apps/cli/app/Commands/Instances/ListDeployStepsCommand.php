<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Responses\Deployments\DeployStepsResponse;

final class ListDeployStepsCommand extends DeploymentCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:deploy-step:list
        {instance : Numeric instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List deploy steps on a production AppInstance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListInstanceDeployStepsRequest($instanceId),
            DeployStepsResponse::class,
            ['List deploy steps', 'Loading deploy steps', 'Loaded deploy steps'],
        );

        if (! $response instanceof DeployStepsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeDeploySteps($response->steps);
        $this->writeHumanMessage('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
