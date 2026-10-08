<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GatewayReleases\ShowGatewayReleaseAutomationRequest;

final class StatusGatewayReleaseAutomationCommand extends GatewayReleaseAutomationCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show whether automatic Gateway releases are enabled or paused, and the last check.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->automation($repository, $connectors, new ShowGatewayReleaseAutomationRequest, ['Show automatic releases', 'Loading automatic releases', 'Loaded automatic releases']);
    }
}
