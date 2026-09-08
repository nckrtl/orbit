<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Nodes\ManagedUserAccount;
use Illuminate\Support\Collection;

/**
 * Development pools keep OPcache enabled but revalidate every file on every
 * request, so a saved file is served immediately. The stock two second
 * opcache.file_update_protection stays: mtime has one second resolution, so a
 * file saved twice within one second would otherwise be cached stale. Sizing
 * lives in the per-version runtime module rendered by PhpFpmRuntimeIniRenderer.
 */
final readonly class AppDevPhpFpmConfigRenderer
{
    /** @param Collection<int, AppDevSite> $sites */
    public function render(Collection $sites, ManagedUserAccount $account): string
    {
        return $sites
            ->sortBy('scope')
            ->map(static function (AppDevSite $site) use ($account): string {
                $user = $site->executionUser($account->user);
                $group = $site->executionUser($account->group);
                $home = $site->executionHome($account->home);
                $production = $site->environment === 'production';
                $maxChildren = $production ? 20 : 10;
                $clearEnvironment = $production ? 'yes' : 'no';
                $homeEnvironment = $production ? "env[HOME] = {$home}\nenv[USER] = {$user}\n" : '';
                $validateTimestamps = $production ? 0 : 1;
                $revalidateFrequency = $production ? '' : "php_admin_value[opcache.revalidate_freq] = 0\n";

                return <<<FPM
                    [{$site->poolName()}]
                    user = {$user}
                    group = {$group}
                    listen = {$site->socketPath()}
                    listen.owner = {$user}
                    listen.group = caddy
                    listen.mode = 0660
                    pm = ondemand
                    pm.max_children = {$maxChildren}
                    pm.process_idle_timeout = 10s
                    pm.max_requests = 500
                    chdir = {$site->checkoutPath}
                    catch_workers_output = yes
                    clear_env = {$clearEnvironment}
                    {$homeEnvironment}env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin
                    php_admin_value[opcache.validate_timestamps] = {$validateTimestamps}
                    {$revalidateFrequency}
                    FPM;
            })
            ->implode(PHP_EOL);
    }
}
