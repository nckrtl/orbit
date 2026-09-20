<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Data\ProxyCli\ProxyCliStatusData;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;

final readonly class ShowProxyCliStatusAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSnapshotStore $snapshots,
    ) {}

    public function execute(): ProxyCliStatusData
    {
        return new ProxyCliStatusData(
            $this->state->enabled(),
            $this->state->nodeId(),
            $this->state->cacheConnection(),
            $this->snapshots->snapshot()?->collectedAt,
            ProxyCliHostname::Value,
        );
    }
}
