<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;

final readonly class ListProxyCliProvidersAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSnapshotStore $snapshots,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $this->state->assertEnabled();

        return $this->snapshots->snapshot()?->toArray()['providers'] ?? [];
    }
}
