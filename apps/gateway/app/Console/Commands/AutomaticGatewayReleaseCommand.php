<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;

/**
 * One tick of automatic Gateway releases, started every minute by `orbit-gateway-release.timer`.
 * It prints the tick as one JSON object. Only a release that failed exits 1; a quiet skip, such as
 * disabled, paused, busy, up to date, or a GitHub error, exits 0.
 */
final class AutomaticGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:auto';

    #[\Override]
    protected $description = 'Release the newest green commit when automatic Gateway releases are enabled and not paused.';

    public function handle(RunAutomaticGatewayReleaseAction $action): int
    {
        $tick = $action->execute();
        $this->emit($tick);

        return in_array($tick['result'], ['failed', 'paused'], true) && $tick['record'] !== null ? self::FAILURE : self::SUCCESS;
    }
}
