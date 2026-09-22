<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Commands\Instances\Concerns\RendersLifecycleSteps;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\CreateProjectLifecycleStepRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;

class CreateSetupStepCommand extends GatewayCommand
{
    use RendersLifecycleSteps;

    #[\Override]
    protected $signature = 'instance:setup-step:create
        {name : Step name}
        {--project= : Numeric Project ID}
        {--command= : Shell command the Gateway runs}
        {--timeout= : Timeout in seconds}
        {--before= : Place before this step}
        {--after= : Place after this step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record one named setup step on a Project.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->createStep($repository, $connectors, 'setup-steps');
    }

    protected function createStep(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, string $collection): int
    {
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

        $response = $this->sendWithProgress($connector, new CreateProjectLifecycleStepRequest(
            projectId: $projectId,
            collection: $collection,
            name: $name,
            command: $command,
            timeoutSeconds: $timeout,
            before: $this->stringOption('before'),
            after: $this->stringOption('after'),
        ), LifecycleStepResponse::class, ['Create step', 'Creating step', 'Created step']);

        return $response instanceof LifecycleStepResponse ? $this->renderLifecycleStep($response) : self::FAILURE;
    }
}
