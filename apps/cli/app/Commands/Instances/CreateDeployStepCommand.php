<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\CreateInstanceDeployStepRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;

final class CreateDeployStepCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:deploy-step:create
        {instance : Numeric instance ID}
        {name : Deploy step name}
        {--command= : Command the Gateway runs}
        {--phase=before_activation : before_activation or after_activation}
        {--timeout= : Timeout in seconds}
        {--before= : Place before this step in the same phase}
        {--after= : Place after this step in the same phase}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a named deploy step on a production AppInstance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        $name = $this->stringArgument('name', 'Deploy step name', 'deploy_step.name_required');
        $command = $this->stringOption('command');

        if ($instanceId === null || $name === null) {
            return self::FAILURE;
        }

        if ($command === null) {
            return $this->renderGatewayFailure('deploy_step.command_required', 'A deploy step command is required.');
        }

        $timeout = $this->option('timeout');
        $timeoutSeconds = null;

        if ($timeout !== null) {
            $timeoutSeconds = filter_var($timeout, FILTER_VALIDATE_INT);

            if (! is_int($timeoutSeconds)) {
                return $this->renderGatewayFailure('deploy_step.timeout_invalid', 'Timeout must be an integer.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new CreateInstanceDeployStepRequest(
            appInstanceId: $instanceId,
            name: $name,
            command: $command,
            phase: $this->stringOption('phase'),
            timeoutSeconds: $timeoutSeconds,
            before: $this->stringOption('before'),
            after: $this->stringOption('after'),
        ), DeploymentStepResponse::class);

        if (! $response instanceof DeploymentStepResponse) {
            return self::FAILURE;
        }

        return $this->renderStep($response);
    }

    private function renderStep(DeploymentStepResponse $step): int
    {
        if ($this->option('json') === true) {
            $this->writeJson([...$step->toArray(), 'request_id' => $step->requestId]);

            return self::SUCCESS;
        }

        $this->line('Name: '.$this->terminalValue($step->name));
        $this->line('Phase: '.$step->phase);
        $this->line('Command: '.$this->terminalValue($step->command));
        $this->line("Timeout: {$step->timeoutSeconds} seconds");

        return self::SUCCESS;
    }
}
