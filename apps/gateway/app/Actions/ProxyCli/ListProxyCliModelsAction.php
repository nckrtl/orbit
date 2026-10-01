<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\ProxyCli\ProxyCliModel;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;

final readonly class ListProxyCliModelsAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSnapshotStore $snapshots,
    ) {}

    /**
     * @return list<ProxyCliModel>
     */
    public function execute(): array
    {
        $this->state->assertEnabled();
        $snapshot = $this->snapshots->snapshot();

        return $snapshot === null ? [] : $snapshot->models;
    }
}
