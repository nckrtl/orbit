<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GatewayReleases\ResumeGatewayReleaseAutomationRequest;

final class ResumeGatewayReleaseAutomationCommand extends GatewayReleaseAutomationCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto:resume
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clear a pause of automatic Gateway releases after you decided how to recover.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->automation($repository, $connectors, new ResumeGatewayReleaseAutomationRequest, ['Resume automatic releases', 'Resuming automatic releases', 'Resumed automatic releases']);
    }
}
