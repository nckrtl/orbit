<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;

final class ConfigureGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:configure';

    #[\Override]
    protected $description = 'Cache the current Gateway release configuration again after a change to the shared env file.';

    public function handle(ConfigureGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->execute());
    }
}
