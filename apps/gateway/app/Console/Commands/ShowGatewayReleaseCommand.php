<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GatewayReleases\ShowGatewayReleaseAction;

final class ShowGatewayReleaseCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:show {release : Record id, or a hex SHA of 7 to 40 characters such as the 12-digit release id}';

    #[\Override]
    protected $description = 'Show one Gateway release record, or the newest record of a commit.';

    public function handle(ShowGatewayReleaseAction $action): int
    {
        return $this->report(fn (): array => ['release' => $action->execute((string) $this->argument('release'))]);
    }
}
