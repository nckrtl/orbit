<?php

declare(strict_types=1);

namespace App\Commands\Fleet;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Fleet\ShowFleetRolloutRequest;
use Orbit\Sdk\Responses\Fleet\FleetRolloutStatusResponse;

final class ShowFleetRolloutCommand extends FleetRolloutCommand
{
    #[\Override]
    protected $signature = 'fleet:rollout:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the newest fleet rollout, each Node\'s result, and the Nodes it leaves out.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $status = $this->sendWithProgress($connector, new ShowFleetRolloutRequest, FleetRolloutStatusResponse::class, ['Show fleet rollout', 'Loading fleet rollout', 'Loaded fleet rollout']);

        return $status instanceof FleetRolloutStatusResponse
            ? $this->renderRollout($status, 'Fleet rollout')
            : self::FAILURE;
    }
}
