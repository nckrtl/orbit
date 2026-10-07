<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;

/**
 * The runtime handoff step of a release, run from the release that just became current. A deploy, rollback, or
 * switch-back runs it under the release lock. An operator can run it again after fixing a handoff failure.
 */
final class HandoffGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:handoff
        {--phase=all : serve (Caddy, PHP-FPM, units), schedule (scheduler, document cleanup, OPcache), or all}';

    #[\Override]
    protected $description = 'Hand Caddy, PHP-FPM, the scheduler, document cleanup, and agent-view over to the current Gateway release.';

    public function handle(GatewayRuntimeHandoff $handoff): int
    {
        return $this->report(fn (): array => match ($this->option('phase')) {
            'serve' => $handoff->serve(),
            'schedule' => $handoff->schedule(),
            default => $handoff->run(),
        });
    }
}
