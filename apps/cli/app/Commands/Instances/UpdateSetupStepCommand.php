<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Commands\Instances\Concerns\RendersLifecycleSteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\UpdateProjectLifecycleStepRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;

class UpdateSetupStepCommand extends GatewayCommand
{
    use RendersLifecycleSteps;

    #[\Override]
    protected $signature = 'instance:setup-step:update
        {name : Step name}
        {--project= : Numeric Project ID}
        {--command= : Replacement shell command}
        {--timeout= : Timeout in seconds}
        {--before= : Place before this step}
        {--after= : Place after this step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change one named setup step.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->updateStep($repository, $connectors, 'setup-steps');
    }

    protected function updateStep(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, string $collection): int
    {
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

        $response = $this->sendWithProgress($connector, new UpdateProjectLifecycleStepRequest(
            projectId: $projectId,
            collection: $collection,
            name: $name,
            command: $this->stringOption('command'),
            timeoutSeconds: $timeout,
            before: $this->stringOption('before'),
            after: $this->stringOption('after'),
        ), LifecycleStepResponse::class, ['Update step', 'Updating step', 'Updated step']);

        return $response instanceof LifecycleStepResponse ? $this->renderLifecycleStep($response) : self::FAILURE;
    }
}
