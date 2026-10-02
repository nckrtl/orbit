<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentDeploySteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\ListProjectDevelopmentDeployStepsRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepsResponse;

class ListDevelopmentDeployStepCommand extends GatewayCommand
{
    use RendersDevelopmentDeploySteps;

    #[\Override]
    protected $signature = 'project:dev-deploy-step:list
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List a Project\'s development deploy steps.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $label = 'development deploy steps';
        $projectId = $this->projectId();

        if ($projectId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListProjectDevelopmentDeployStepsRequest($projectId),
            DevelopmentDeployStepsResponse::class,
            ["List {$label}", "Loading {$label}", "Loaded {$label}"],
        );

        return $response instanceof DevelopmentDeployStepsResponse ? $this->renderDevelopmentDeploySteps($response) : self::FAILURE;
    }
}
