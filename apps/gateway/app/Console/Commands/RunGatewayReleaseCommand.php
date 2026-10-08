<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\RunRequestedGatewayReleaseAction;

/**
 * Runs one release that an operator requested through the API. `orbit-gateway-release-run@<record>.service`
 * starts it with the queued record's id, so the release runs outside PHP-FPM and the scheduler.
 */
final class RunGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:run {record : Id of the queued release record}';

    #[\Override]
    protected $description = 'Run a queued Gateway deploy or rollback and record its outcome.';

    public function handle(RunRequestedGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->execute((string) $this->argument('record'))->payload());
    }
}
