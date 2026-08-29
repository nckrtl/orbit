<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Nodes\ManagedUserAccount;
use Illuminate\Support\Collection;

final readonly class AppDevPhpFpmConfigRenderer
{
    /** @param Collection<int, AppDevSite> $sites */
    public function render(Collection $sites, ManagedUserAccount $account): string
    {
        return $sites
            ->sortBy('scope')
            ->map(static fn (AppDevSite $site): string => <<<FPM
                [{$site->poolName()}]
                user = {$account->user}
                group = {$account->group}
                listen = {$site->socketPath()}
                listen.owner = {$account->user}
                listen.group = caddy
                listen.mode = 0660
                pm = ondemand
                pm.max_children = 10
                pm.process_idle_timeout = 10s
                pm.max_requests = 500
                chdir = {$site->checkoutPath}
                catch_workers_output = yes
                clear_env = no
                env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin

                FPM)
            ->implode(PHP_EOL);
    }
}
