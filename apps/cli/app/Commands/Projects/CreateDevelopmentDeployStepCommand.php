<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentDeploySteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\CreateProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;

class CreateDevelopmentDeployStepCommand extends GatewayCommand
{
    use RendersDevelopmentDeploySteps;

    #[\Override]
    protected $signature = 'project:dev-deploy-step:create
        {name : Step name}
        {--project= : Numeric Project ID}
        {--command= : Shell command the Gateway runs}
        {--timeout= : Timeout in seconds}
        {--required= : true for required or false for best effort}
        {--before= : Place before this step}
        {--after= : Place after this step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record one named development deploy step on a Project.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {

        $required = $this->developmentRequired($this->option('required'));

        if ($required === 'invalid') {
            return $this->renderGatewayFailure('development_deploy_step.required_invalid', 'Required must be true or false.');
        }

        $projectId = $this->projectId();
        $name = $this->lifecycleName();
        $command = $this->stringOption('command');
        $timeout = $this->lifecycleTimeout($this->option('timeout'));

        if ($projectId === null || $name === null || $timeout === false) {
            if ($timeout === false) {
                return $this->renderGatewayFailure('lifecycle_step.timeout_invalid', 'Timeout must be an integer.');
            }

            return self::FAILURE;
        }

        if ($command === null) {
            return $this->renderGatewayFailure('lifecycle_step.command_required', 'A step command is required.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new CreateProjectDevelopmentDeployStepRequest(
            projectId: $projectId,
            name: $name,
            command: $command,
            timeoutSeconds: $timeout,
            before: $this->stringOption('before'),
            after: $this->stringOption('after'),
            required: $required,
        ), DevelopmentDeployStepResponse::class, ['Create step', 'Creating step', 'Created step']);

        return $response instanceof DevelopmentDeployStepResponse ? $this->renderDevelopmentDeployStep($response) : self::FAILURE;
    }
}
