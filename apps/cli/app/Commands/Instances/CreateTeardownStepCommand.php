<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;

final class CreateTeardownStepCommand extends CreateSetupStepCommand
{
    #[\Override]
    protected $signature = 'instance:teardown-step:create
        {name : Step name}
        {--project= : Numeric Project ID}
        {--command= : Shell command the Gateway runs}
        {--timeout= : Timeout in seconds}
        {--before= : Place before this step}
        {--after= : Place after this step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record one named teardown step on a Project.';

    #[\Override]
    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->createStep($repository, $connectors, 'teardown-steps');
    }
}
