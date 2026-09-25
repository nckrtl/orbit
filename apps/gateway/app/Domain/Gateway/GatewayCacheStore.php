<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use RuntimeException;

/**
 * The Gateway's locks and short-lived state live in its default cache store. The Gateway has no cache or
 * cache-lock tables, and a null store never excludes a second holder, so both drivers would silently break
 * locking. This guard refuses them when the application boots.
 */
final readonly class GatewayCacheStore
{
    /** @var list<string> */
    public const array UnsupportedDrivers = ['database', 'null'];

    /**
     * @param  array<string, mixed>  $cache  The `cache` configuration.
     */
    public static function assertSupported(array $cache): void
    {
        $store = $cache['default'] ?? null;
        $stores = $cache['stores'] ?? null;
        $driver = is_string($store) && is_array($stores) && is_array($stores[$store] ?? null)
            ? ($stores[$store]['driver'] ?? null)
            : null;

        if (! is_string($driver)) {
            throw new RuntimeException('The Gateway cache store ['.(is_string($store) ? $store : '').'] is not configured. Set CACHE_STORE=file in the Gateway .env.');
        }

        if (in_array($driver, self::UnsupportedDrivers, true)) {
            throw new RuntimeException("The Gateway cache store [{$store}] uses the [{$driver}] driver, which cannot hold Gateway locks. Set CACHE_STORE=file in the Gateway .env.");
        }
    }
}
