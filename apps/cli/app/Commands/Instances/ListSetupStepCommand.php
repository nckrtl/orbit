<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Commands\Instances\Concerns\RendersLifecycleSteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\ListProjectLifecycleStepsRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepsResponse;

class ListSetupStepCommand extends GatewayCommand
{
    use RendersLifecycleSteps;

    #[\Override]
    protected $signature = 'instance:setup-step:list
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List a Project\'s setup steps.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->listSteps($repository, $connectors, 'setup-steps', 'setup steps');
    }

    protected function listSteps(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        string $collection,
        string $label,
    ): int {
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
            new ListProjectLifecycleStepsRequest($projectId, $collection),
            LifecycleStepsResponse::class,
            ["List {$label}", "Loading {$label}", "Loaded {$label}"],
        );

        return $response instanceof LifecycleStepsResponse ? $this->renderLifecycleSteps($response) : self::FAILURE;
    }
}
