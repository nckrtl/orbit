<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use Illuminate\Support\Collection;

final readonly class AppDevCaddyConfigRenderer
{
    /** @param Collection<int, AppDevSite> $sites */
    public function render(Collection $sites): string
    {
        if ($sites->isEmpty()) {
            return '# Orbit has no active app development sites.'.PHP_EOL;
        }

        return (
            $sites
                ->sortBy('hostname')
                ->map(static function (AppDevSite $site): string {
                    $handler = $site->isProxy()
                        ? <<<CADDY
                            reverse_proxy https://{$site->upstreamAddress} {
                                header_up Host {$site->hostname}
                                transport http {
                                    tls_server_name {$site->hostname}
                                }
                            }
                            CADDY
                        : implode(PHP_EOL, array_filter([
                            "root * {$site->checkoutPath}/{$site->documentRoot}",
                            'encode zstd gzip',
                            $site->phpVersion === null ? null : "php_fastcgi unix/{$site->socketPath()}",
                            'file_server',
                        ]));

                    return <<<CADDY
                        https://{$site->hostname} {
                            bind 0.0.0.0
                            tls {$site->certificateDirectory()}/cert.pem {$site->certificateDirectory()}/key.pem
                            {$handler}
                        }
                        CADDY;
                })
                ->implode(PHP_EOL.PHP_EOL).PHP_EOL
        );
    }
}
