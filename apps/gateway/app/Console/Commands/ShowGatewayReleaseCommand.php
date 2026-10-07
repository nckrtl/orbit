<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\ShowGatewayReleaseAction;

final class ShowGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:show {release : First 12 hex digits of the release}';

    #[\Override]
    protected $description = 'Show the newest record for one Gateway release.';

    public function handle(ShowGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => ['release' => $action->execute((string) $this->argument('release'))]);
    }
}
