<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GatewayReleases\EnableGatewayReleaseAutomationRequest;

final class EnableGatewayReleaseAutomationCommand extends GatewayReleaseAutomationCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto:enable
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Turn on automatic releases of green main commits to the active Gateway.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->automation($repository, $connectors, new EnableGatewayReleaseAutomationRequest, ['Enable automatic releases', 'Enabling automatic releases', 'Enabled automatic releases']);
    }
}
