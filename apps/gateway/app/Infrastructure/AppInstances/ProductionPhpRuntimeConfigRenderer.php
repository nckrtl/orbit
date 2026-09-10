<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;

final readonly class ProductionPhpRuntimeConfigRenderer
{
    public function render(ProductionPhpRuntimeIdentity $identity): ProductionPhpRuntimeConfiguration
    {
        $main = <<<FPM
            [global]
            pid = /run/php/{$identity->user}.pid
            error_log = /var/log/php-fpm.log
            include = {$identity->generatedDirectory}/pool.conf
            include = {$identity->localTuningPath}

            FPM;

        $pool = <<<FPM
            [{$identity->pool}]
            user = {$identity->user}
            group = {$identity->user}
            listen = {$identity->socket}
            listen.owner = {$identity->user}
            listen.group = caddy
            listen.mode = 0660
            chdir = {$identity->home}
            clear_env = yes
            env[HOME] = {$identity->home}
            env[USER] = {$identity->user}
            env[PATH] = /usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin

            FPM;

        $localDefaults = <<<FPM
            [{$identity->pool}]
            pm = ondemand
            pm.max_children = 20
            pm.process_idle_timeout = 10s
            pm.max_requests = 500
            catch_workers_output = yes
            php_admin_value[opcache.memory_consumption] = 256
            php_admin_value[opcache.validate_timestamps] = 0

            FPM;

        $unit = <<<SYSTEMD
            [Unit]
            Description=Orbit PHP {$identity->version} FPM for {$identity->user}
            After=network.target

            [Service]
            Type=notify
            ExecStart=/usr/sbin/php-fpm{$identity->version} --nodaemonize --fpm-config {$identity->generatedDirectory}/php-fpm.conf
            ExecReload=/bin/kill -USR2 \$MAINPID
            PIDFile=/run/php/{$identity->user}.pid
            Restart=on-failure

            [Install]
            WantedBy=multi-user.target

            SYSTEMD;

        return new ProductionPhpRuntimeConfiguration($main, $pool, $localDefaults, $unit);
    }
}
