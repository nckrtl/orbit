<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\ResourceOperationException;

final readonly class ShowProxyCliProviderAction
{
    public function __construct(
        private ProxyCliState $state,
        private ProxyCliSnapshotStore $snapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $provider): array
    {
        $this->state->assertEnabled();
        $pool = $this->snapshots->snapshot()?->provider($provider);

        if ($pool === null) {
            throw new ResourceOperationException(
                'resource.not_found',
                "Provider [{$provider}] was not found.",
                404,
            );
        }

        return $pool->toArray();
    }
}
