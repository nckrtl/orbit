<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Commands\Instances\Concerns\RendersLifecycleSteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\DestroyProjectLifecycleStepRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;

class DestroySetupStepCommand extends GatewayCommand
{
    use RendersLifecycleSteps;

    #[\Override]
    protected $signature = 'instance:setup-step:destroy
        {name : Step name}
        {--project= : Numeric Project ID}
        {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one named setup step.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->destroyStep($repository, $connectors, 'setup-steps', 'setup');
    }

    protected function destroyStep(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        string $collection,
        string $label,
    ): int {
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
            new DestroyProjectLifecycleStepRequest($projectId, $collection, $name),
            LifecycleStepResponse::class,
            ['Remove step', 'Removing step', 'Removed step'],
        );

        return $response instanceof LifecycleStepResponse ? $this->renderLifecycleStep($response) : self::FAILURE;
    }
}
