<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\SettleGatewayReleaseAction;

/**
 * Ends release records whose process died. The release units run it from `ExecStopPost`; an
 * operator can run it too. It prints the ids it ended as one JSON object.
 */
final class SettleGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:settle {record? : Id of the release record whose unit stopped}';

    #[\Override]
    protected $description = 'End Gateway release records whose process died as interrupted, and pause when they had touched live state.';

    public function handle(SettleGatewayReleaseAction $action): int
    {
        $record = $this->argument('record');

        return $this->report(fn (): array => ['settled' => $action->execute(is_string($record) ? $record : null)]);
    }
}
