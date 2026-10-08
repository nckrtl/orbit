<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;

final class DeployGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:deploy
        {commit : Hex SHA of the commit to release}
        {--force : Release a commit that does not descend from the current one, or does not know an applied migration}';

    #[\Override]
    protected $description = 'Prepare a Gateway release, switch to it, verify it, and switch back when verification fails.';

    public function handle(DeployGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->execute((string) $this->argument('commit'), (bool) $this->option('force'))->toArray());
    }
}
