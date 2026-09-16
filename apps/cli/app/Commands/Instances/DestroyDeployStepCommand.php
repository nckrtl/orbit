<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use Orbit\Sdk\Responses\Deployments\DeployStepsResponse;

final class DestroyDeployStepCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:deploy-step:destroy
        {instance : Numeric instance ID}
        {name : Deploy step name}
        {--yes : Confirm removal without prompting}
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

        if ($this->option('yes') !== true) {
            $instance = $this->sendWithProgress($connector, new ShowAppInstanceRequest($instanceId), AppInstanceResponse::class,
                ['Resolve App instance', 'Loading App instance', 'Loaded App instance']);
            if (! $instance instanceof AppInstanceResponse) {
                return self::FAILURE;
            }
            $steps = $this->sendWithProgress($connector, new ListInstanceDeployStepsRequest($instanceId), DeployStepsResponse::class,
                ['Resolve deploy step', 'Loading deploy steps', 'Loaded deploy steps']);
            if (! $steps instanceof DeployStepsResponse) {
                return self::FAILURE;
            }
            $step = array_find($steps->steps, static fn (DeploymentStepResponse $candidate): bool => $candidate->name === $name);
            if ($step === null) {
                return $this->renderGatewayFailure('deploy_step.not_found', 'The deploy step was not found.', $steps->requestId);
            }
            if (! $this->confirmAction("Remove deploy step [{$step->name}] from App instance [{$instance->name}] (#{$instance->id})?", 'Deploy step removal cancelled.')) {
                return self::FAILURE;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new DestroyInstanceDeployStepRequest($instanceId, $name),
            DeploymentStepResponse::class,
            ['Remove deploy step', 'Removing deploy step', 'Removed deploy step'],
        );

        if (! $response instanceof DeploymentStepResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([...$response->toArray(), 'request_id' => $response->requestId]);

            return self::SUCCESS;
        }

        $this->writeHumanMessage('Destroyed deploy step '.$response->name.'.');
        $this->writeHumanMessage('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
