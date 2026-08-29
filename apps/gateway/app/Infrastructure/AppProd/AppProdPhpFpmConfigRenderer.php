<?php

declare(strict_types=1);

namespace App\Infrastructure\AppProd;

use Illuminate\Support\Collection;

/**
 * Production pools never stat cached files: compiled code stays in OPcache
 * until the PHP-FPM service reloads. Every production deploy must therefore
 * end with `systemctl reload php<version>-fpm`, which recreates the shared
 * memory and flushes the cache. Sizing lives in PhpFpmRuntimeIniRenderer.
 */
final readonly class AppProdPhpFpmConfigRenderer
{
    /** @param Collection<int, AppProdSite> $sites */
    public function render(Collection $sites): string
    {
        return $sites
            ->sortBy(static fn (AppProdSite $site): string => $site->scope())
            ->map(static fn (AppProdSite $site): string => <<<FPM
                [{$site->poolName()}]
                user = {$site->user()}
                group = {$site->user()}
                listen = {$site->socketPath()}
                listen.owner = {$site->user()}
                listen.group = caddy
                listen.mode = 0660
                pm = ondemand
                pm.max_children = 20
                pm.process_idle_timeout = 10s
                pm.max_requests = 500
                chdir = {$site->checkoutPath}
                catch_workers_output = yes
                access.log = /var/log/orbit/php-fpm/{$site->scope()}.access.log
                slowlog = /var/log/orbit/php-fpm/{$site->scope()}.slow.log
                request_slowlog_timeout = 10s
                clear_env = yes
                env[HOME] = {$site->appRoot()}
                env[USER] = {$site->user()}
                env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin
                php_admin_value[opcache.validate_timestamps] = 0

                FPM)
            ->implode(PHP_EOL);
    }
}
