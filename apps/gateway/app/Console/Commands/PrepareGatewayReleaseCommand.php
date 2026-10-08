<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\PrepareGatewayReleaseAction;

final class PrepareGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:prepare {commit : Hex SHA of the commit to build}';

    #[\Override]
    protected $description = 'Build an immutable Gateway release for one commit without changing the live release.';

    public function handle(PrepareGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->execute((string) $this->argument('commit'))->toArray());
    }
}
