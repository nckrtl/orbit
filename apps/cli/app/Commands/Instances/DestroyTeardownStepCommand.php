<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;

final class DestroyTeardownStepCommand extends DestroySetupStepCommand
{
    #[\Override]
    protected $signature = 'instance:teardown-step:destroy
        {name : Step name}
        {--project= : Numeric Project ID}
        {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one named teardown step.';

    #[\Override]
    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->destroyStep($repository, $connectors, 'teardown-steps', 'teardown');
    }
}
