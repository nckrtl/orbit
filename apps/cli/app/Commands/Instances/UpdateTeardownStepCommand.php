<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;

final class UpdateTeardownStepCommand extends UpdateSetupStepCommand
{
    #[\Override]
    protected $signature = 'instance:teardown-step:update
        {name : Step name}
        {--project= : Numeric Project ID}
        {--command= : Replacement shell command}
        {--timeout= : Timeout in seconds}
        {--before= : Place before this step}
        {--after= : Place after this step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change one named teardown step.';

    #[\Override]
    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->updateStep($repository, $connectors, 'teardown-steps');
    }
}
