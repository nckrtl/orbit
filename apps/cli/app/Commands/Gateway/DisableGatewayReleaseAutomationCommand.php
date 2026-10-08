<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GatewayReleases\DisableGatewayReleaseAutomationRequest;

final class DisableGatewayReleaseAutomationCommand extends GatewayReleaseAutomationCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto:disable
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Turn off automatic Gateway releases.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->automation($repository, $connectors, new DisableGatewayReleaseAutomationRequest, ['Disable automatic releases', 'Disabling automatic releases', 'Disabled automatic releases']);
    }
}
