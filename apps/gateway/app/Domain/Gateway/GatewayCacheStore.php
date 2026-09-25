<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use RuntimeException;

/**
 * The Gateway's locks and short-lived state live in its default cache store, and its locks must exclude other
 * processes: the scheduler's tick, queue-less Artisan commands, and PHP-FPM workers. The Gateway has no cache or
 * cache-lock tables, a null store never excludes a second holder, and an array store lives in one process, so the
 * Gateway accepts only cross-process stores when the application boots. Tests may use the array store.
 */
final readonly class GatewayCacheStore
{
    /** @var list<string> */
    public const array CrossProcessDrivers = ['file', 'redis', 'memcached', 'dynamodb'];

    /**
     * Artisan commands that run past the guard, so an operator can clear a stale cached configuration and reinstall.
     *
     * @var list<string>
     */
    public const array RecoveryCommands = ['config:clear', 'optimize:clear', 'package:discover'];

    /**
     * @param  array<string, mixed>  $cache  The `cache` configuration.
     */
    public static function assertSupported(array $cache, string $environment, bool $configurationIsCached = false): void
    {
        $store = $cache['default'] ?? null;
        $stores = $cache['stores'] ?? null;
        $driver = is_string($store) && is_array($stores) && is_array($stores[$store] ?? null)
            ? ($stores[$store]['driver'] ?? null)
            : null;

        if (! is_string($driver)) {
            throw self::refused('The Gateway cache store ['.(is_string($store) ? $store : '').'] is not configured.', $configurationIsCached);
        }

        if (in_array($driver, self::CrossProcessDrivers, true) || ($driver === 'array' && $environment === 'testing')) {
            return;
        }

        throw self::refused("The Gateway cache store [{$store}] uses the [{$driver}] driver, which cannot hold Gateway locks across processes.", $configurationIsCached);
    }

    private static function refused(string $reason, bool $configurationIsCached): RuntimeException
    {
        $message = $reason.' Set CACHE_STORE=file in the Gateway .env.';
        if ($configurationIsCached) {
            $message .= ' The configuration is cached: run php artisan config:clear (or delete bootstrap/cache/config.php).';
        }

        return new RuntimeException($message);
    }
}
