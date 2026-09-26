<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeLockLoss;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Per-Node operation locks in their own file cache store under `ORBIT_HOME`, shared by every PHP-FPM
 * worker and Artisan command on the Gateway. The store is pinned here, whatever `CACHE_STORE` says,
 * so a lock never depends on a `cache_locks` table or another store's configuration.
 *
 * The locks this process holds are renewed before each command it runs (LockRenewingProcessRunner),
 * so an operation keeps them for as long as it runs, however long that is.
 */
final class NodeLocks
{
    /**
     * The operation term in a Gateway request: the Gateway's PHP-FPM `request_terminate_timeout`. A
     * request cannot outlive it.
     */
    public const int RequestSeconds = 600;

    /**
     * The operation term in an Artisan command, which has no time limit: the longest single command,
     * 900 seconds (ProcessInvocation and SshConnection), with 5 minutes to spare. The renewal before
     * each command then keeps the lock for the whole operation.
     */
    public const int ConsoleSeconds = 1200;

    /** @var array<string, NodeLock> */
    private array $held = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly bool $console = false,
    ) {}

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

    /** The term of a lock that one operation holds in this process. */
    public function operationSeconds(): int
    {
        return $this->console ? self::ConsoleSeconds : self::RequestSeconds;
    }

    public function lock(string $name, int $seconds): NodeLock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The Node lock store cannot hold locks.');
        }

        $lock = $store->lock('orbit:'.$name, $seconds);

        if (! $lock instanceof CacheLock) {
            throw new LogicException('The Node lock store cannot renew locks.');
        }

        return new NodeLock($this, $lock, $name, $seconds);
    }

    /**
     * Renews every lock this process holds for its full term. When one has expired, whether or not
     * another operation has taken it since, the command about to run must not run: it fails with
     * `node.lock_lost`, and so does every later command until the operation releases the lock.
     */
    public function renewHeld(): void
    {
        foreach ($this->held as $name => $lock) {
            if (! $lock->refresh()) {
                throw NodeLockLoss::exception($name);
            }
        }
    }

    /** @internal Called by NodeLock when this process acquires it. */
    public function hold(string $name, NodeLock $lock): void
    {
        $this->held[$name] = $lock;
    }

    /** @internal Called by NodeLock when this process releases it. */
    public function forget(string $name, NodeLock $lock): void
    {
        if (($this->held[$name] ?? null) === $lock) {
            unset($this->held[$name]);
        }
    }
}
