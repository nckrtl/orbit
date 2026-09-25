<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

final readonly class PrivateDnsListenerFactory
{
    public function make(
        string $catalogPath,
        string $listenAddress,
        int $port,
        string $upstream,
    ): PrivateDnsTransportServer {
        $cache = new InMemoryPrivateDnsAnswerCache;
        $store = new FilePrivateDnsCatalogStore($catalogPath, $cache, FilePrivateDnsCatalogStore::loadedPath($catalogPath));
        [$host, $upstreamPort] = $this->upstream($upstream);

        return new PrivateDnsTransportServer(
            handler: new PrivateDnsRequestHandler(
                requesters: new StoredPrivateDnsRequesterResolver($store),
                selector: new StoredPrivateDnsAnswerSelector($store),
                cache: $cache,
                upstream: new SocketPrivateDnsUpstream($host, $upstreamPort),
            ),
            listenAddress: $listenAddress,
            port: $port,
            // A quiet listener still loads a new catalog within a second.
            onIdle: static fn (): bool => $store->refresh(),
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function upstream(string $upstream): array
    {
        if (str_contains($upstream, ':')) {
            [$host, $port] = explode(':', $upstream, 2);

            return [$host, (int) $port];
        }

        return [$upstream, 53];
    }
}
