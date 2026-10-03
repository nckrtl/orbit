<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentDeploySteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\UpdateProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;

class UpdateDevelopmentDeployStepCommand extends GatewayCommand
{
    use RendersDevelopmentDeploySteps;

    #[\Override]
    protected $signature = 'project:dev-deploy-step:update
        {name : Step name}
        {--project= : Numeric Project ID}
        {--command= : Replacement shell command}
        {--timeout= : Timeout in seconds}
        {--required= : true for required or false for best effort}
        {--before= : Place before this step}
        {--after= : Place after this step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change one named development deploy step.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {

        $required = $this->developmentRequired($this->option('required'));

        if ($required === 'invalid') {
            return $this->renderGatewayFailure('development_deploy_step.required_invalid', 'Required must be true or false.');
        }

        $projectId = $this->projectId();
        $name = $this->lifecycleName();
        $timeout = $this->lifecycleTimeout($this->option('timeout'));

        if ($projectId === null || $name === null || $timeout === false) {
            if ($timeout === false) {
                return $this->renderGatewayFailure('lifecycle_step.timeout_invalid', 'Timeout must be an integer.');
            }

            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new UpdateProjectDevelopmentDeployStepRequest(
            projectId: $projectId,
            name: $name,
            command: $this->stringOption('command'),
            timeoutSeconds: $timeout,
            before: $this->stringOption('before'),
            after: $this->stringOption('after'),
            required: $required,
        ), DevelopmentDeployStepResponse::class, ['Update step', 'Updating step', 'Updated step']);

        return $response instanceof DevelopmentDeployStepResponse ? $this->renderDevelopmentDeployStep($response) : self::FAILURE;
    }
}
