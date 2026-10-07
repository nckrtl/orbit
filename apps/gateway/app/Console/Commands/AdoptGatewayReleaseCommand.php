<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\AdoptGatewayReleaseAction;

final class AdoptGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:adopt';

    #[\Override]
    protected $description = 'Convert the in-place Gateway checkout into the immutable release layout, once.';

    public function handle(AdoptGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => $action->execute());
    }
}
