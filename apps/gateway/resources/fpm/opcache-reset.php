<?php

declare(strict_types=1);

/*
 * Runs inside the orbit-gateway PHP-FPM pool, which the Gateway reaches over its socket after a release switch
 * (docs/reference/gateway-recovery.md#runtime-handoff). It is outside public/, so Caddy never serves it.
 * `?status=1` reports without resetting.
 */

$status = static function (): ?array {
    $current = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

    if (! is_array($current)) {
        return null;
    }

    return [
        'cache_full' => $current['cache_full'] ?? null,
        'used_memory' => $current['memory_usage']['used_memory'] ?? null,
        'free_memory' => $current['memory_usage']['free_memory'] ?? null,
        'wasted_memory' => $current['memory_usage']['wasted_memory'] ?? null,
        'num_cached_scripts' => $current['opcache_statistics']['num_cached_scripts'] ?? null,
        'restart_pending' => $current['restart_pending'] ?? null,
    ];
};

$before = $status();
$reset = ($_GET['status'] ?? '') !== '1' && function_exists('opcache_reset') && opcache_reset();

header('Content-Type: application/json');
echo json_encode(['reset' => $reset, 'force_restart_timeout' => (int) ini_get('opcache.force_restart_timeout'), 'before' => $before], JSON_THROW_ON_ERROR);
