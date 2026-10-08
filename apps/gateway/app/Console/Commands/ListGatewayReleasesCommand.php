<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\ListGatewayReleasesAction;

final class ListGatewayReleasesCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:list';

    #[\Override]
    protected $description = 'List recorded Gateway releases, newest first.';

    public function handle(ListGatewayReleasesAction $action): int
    {
        return $this->report(fn (): array => ['releases' => $action->execute()]);
    }
}
