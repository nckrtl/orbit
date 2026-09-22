<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;

final class ListTeardownStepCommand extends ListSetupStepCommand
{
    #[\Override]
    protected $signature = 'instance:teardown-step:list
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List a Project\'s teardown steps.';

    #[\Override]
    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->listSteps($repository, $connectors, 'teardown-steps', 'teardown steps');
    }
}
