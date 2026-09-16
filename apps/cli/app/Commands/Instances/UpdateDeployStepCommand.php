<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\UpdateInstanceDeployStepRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;

final class UpdateDeployStepCommand extends DeploymentCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:deploy-step:update
        {instance : Numeric instance ID}
        {name : Deploy step name}
        {--command= : Command the Gateway runs}
        {--phase= : before_activation or after_activation}
        {--timeout= : Timeout in seconds}
        {--before= : Place before this step in the same phase}
        {--after= : Place after this step in the same phase}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a named deploy step on a production AppInstance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        $name = $this->stringArgument('name', 'Deploy step name', 'deploy_step.name_required');

        if ($instanceId === null || $name === null) {
            return self::FAILURE;
        }

        $hasCommand = $this->input->getOption('command') !== null;
        $hasPhase = $this->input->getOption('phase') !== null;
        $hasTimeout = $this->input->getOption('timeout') !== null;
        $hasBefore = $this->input->getOption('before') !== null;
        $hasAfter = $this->input->getOption('after') !== null;

        if (! $hasCommand && ! $hasPhase && ! $hasTimeout && ! $hasBefore && ! $hasAfter) {
            return $this->renderGatewayFailure(
                'deploy_step.update_required',
                'Provide at least one deploy step update option.',
            );
        }

        $timeoutSeconds = null;

        if ($hasTimeout) {
            $timeoutSeconds = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);

            if (! is_int($timeoutSeconds)) {
                return $this->renderGatewayFailure('deploy_step.timeout_invalid', 'Timeout must be an integer.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new UpdateInstanceDeployStepRequest(
            appInstanceId: $instanceId,
            name: $name,
            hasCommand: $hasCommand,
            command: $hasCommand ? (string) $this->option('command') : null,
            hasPhase: $hasPhase,
            phase: $hasPhase ? (string) $this->option('phase') : null,
            hasTimeout: $hasTimeout,
            timeoutSeconds: $timeoutSeconds,
            hasBefore: $hasBefore,
            before: $hasBefore ? (string) $this->option('before') : null,
            hasAfter: $hasAfter,
            after: $hasAfter ? (string) $this->option('after') : null,
        ), DeploymentStepResponse::class, ['Update deploy step', 'Updating deploy step', 'Updated deploy step']);

        if (! $response instanceof DeploymentStepResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson([...$response->toArray(), 'request_id' => $response->requestId]);

            return self::SUCCESS;
        }

        $this->writeDeployStep($response);

        return self::SUCCESS;
    }
}
