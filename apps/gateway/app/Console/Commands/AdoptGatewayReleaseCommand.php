<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\AdoptGatewayReleaseAction;

final class AdoptGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:adopt
        {--commit= : Hex SHA to adopt into; run this from a checkout of that commit. Defaults to the in-place checkout\'s own commit}';

    #[\Override]
    protected $description = 'Convert the in-place Gateway checkout into the immutable release layout, once.';

    public function handle(AdoptGatewayReleaseAction $action): int
    {
        $commit = $this->option('commit');

        return $this->report(fn (): array => $action->execute(
            is_string($commit) && $commit !== '' ? $commit : null,
            // The repository root of the checkout this command runs from, which may be outside the layout.
            dirname(base_path(), 2),
        ));
    }
}
