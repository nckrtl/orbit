<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Data\Gateway\GatewayStatusData;
use App\Domain\Fleet\DesiredFleetState;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;

final readonly class ShowGatewayStatusAction
{
    public function __construct(private DesiredFleetState $fleet) {}

    /** The desired fleet state is shown only to an active WireGuard peer, as the release endpoint does. */
    public function handle(bool $authenticated): GatewayStatusData
    {
        return new GatewayStatusData(
            name: 'orbit-gateway',
            status: 'ok',
            version: Config::string('app.version'),
            phpVersion: PHP_VERSION,
            laravelVersion: Application::VERSION,
            desiredFleetState: $authenticated ? $this->fleet->current() : null,
        );
    }
}
