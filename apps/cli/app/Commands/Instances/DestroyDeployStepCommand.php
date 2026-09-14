<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;

final class DestroyDeployStepCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:deploy-step:destroy
        {instance : Numeric instance ID}
        {name : Deploy step name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy a named deploy step on a production AppInstance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        $name = $this->stringArgument('name', 'Deploy step name', 'deploy_step.name_required');

        if ($instanceId === null || $name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new DestroyInstanceDeployStepRequest($instanceId, $name),
            DeploymentStepResponse::class,
        );

        if (! $response instanceof DeploymentStepResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([...$response->toArray(), 'request_id' => $response->requestId]);

            return self::SUCCESS;
        }

        $this->line('Destroyed deploy step '.$this->terminalValue($response->name).'.');

        return self::SUCCESS;
    }
}
