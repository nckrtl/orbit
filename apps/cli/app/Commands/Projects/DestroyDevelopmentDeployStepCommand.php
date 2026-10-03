<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentDeploySteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\DestroyProjectDevelopmentDeployStepRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;

class DestroyDevelopmentDeployStepCommand extends GatewayCommand
{
    use RendersDevelopmentDeploySteps;

    #[\Override]
    protected $signature = 'project:dev-deploy-step:destroy
        {name : Step name}
        {--project= : Numeric Project ID}
        {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one named development deploy step.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $label = 'development deploy';
        $projectId = $this->projectId();
        $name = $this->lifecycleName();

        if ($projectId === null || $name === null) {
            return self::FAILURE;
        }

        if ($this->option('yes') !== true && ! $this->confirmAction(
            "Remove {$label} step [{$name}] from Project [{$projectId}]?",
            'Step removal cancelled.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DestroyProjectDevelopmentDeployStepRequest($projectId, $name),
            DevelopmentDeployStepResponse::class,
            ['Remove step', 'Removing step', 'Removed step'],
        );

        return $response instanceof DevelopmentDeployStepResponse ? $this->renderDevelopmentDeployStep($response) : self::FAILURE;
    }
}
