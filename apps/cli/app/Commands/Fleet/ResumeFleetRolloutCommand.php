<?php

declare(strict_types=1);

namespace App\Commands\Fleet;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Fleet\ResumeFleetRolloutRequest;
use Orbit\Sdk\Responses\Fleet\FleetRolloutStatusResponse;

final class ResumeFleetRolloutCommand extends FleetRolloutCommand
{
    #[\Override]
    protected $signature = 'fleet:rollout:resume
        {--skip= : Node ID or name to leave out of this rollout, usually the Node it halted on}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Resume the halted fleet rollout. The Gateway runs it in orbit-fleet-converge.service.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $status = $this->sendWithProgress(
            $connector,
            new ResumeFleetRolloutRequest($this->stringOption('skip')),
            FleetRolloutStatusResponse::class,
            ['Resume fleet rollout', 'Resuming fleet rollout', 'Resumed fleet rollout'],
        );

        return $status instanceof FleetRolloutStatusResponse
            ? $this->renderRollout($status, 'Fleet rollout resumed')
            : self::FAILURE;
    }
}
