<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Per-Node operation locks in their own file cache store under `ORBIT_HOME`, shared by every PHP-FPM
 * worker and Artisan command on the Gateway. The store is pinned here, whatever `CACHE_STORE` says,
 * so a lock never depends on a `cache_locks` table or another store's configuration.
 */
final readonly class NodeLocks
{
    public function __construct(private Repository $cache) {}

    /**
     * The file store the Gateway builds for the locks.
     *
     * @return array{driver: string, path: string, lock_path: string}
     */
    public static function storeConfiguration(string $orbitHome): array
    {
        $path = rtrim($orbitHome, '/').'/cache/node-locks';

        return ['driver' => 'file', 'path' => $path, 'lock_path' => $path];
    }

    public function lock(string $name, int $seconds): Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The Node lock store cannot hold locks.');
        }

        return $store->lock('orbit:'.$name, $seconds);
    }
}
