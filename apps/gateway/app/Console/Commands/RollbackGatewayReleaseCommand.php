<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;

final class RollbackGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:rollback {release : First 12 hex digits of the release to restore} {--force : Switch even when the current release has migrations the target does not}';

    #[\Override]
    protected $description = 'Switch the Gateway back to a retained release.';

    public function handle(RollbackGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->execute((string) $this->argument('release'), (bool) $this->option('force'))->toArray());
    }
}
