<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\SmokeGatewayReleaseAction;

final class SmokeGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:smoke
        {commit? : Hex SHA the live Gateway must serve. Default: the current release}
        {--since= : Runtime handoff time, ISO 8601 with a zone. The scheduler and agent view must have started after it}';

    #[\Override]
    protected $description = 'Run bin/gateway-smoke of the current release against the live Gateway. Changes nothing.';

    public function handle(SmokeGatewayReleaseAction $action): int
    {
        $commit = $this->argument('commit');
        $since = $this->option('since');

        return $this->report(fn (): array => $action->execute(
            is_string($commit) ? $commit : null,
            is_string($since) ? $since : null,
        ));
    }
}
