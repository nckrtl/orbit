<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentServerEndpoint;
use Illuminate\Support\Collection;

final readonly class AppDevCaddyConfigRenderer
{
    /** @param Collection<int, AppDevSite> $sites */
    public function render(Collection $sites): string
    {
        if ($sites->isEmpty()) {
            return '# Orbit has no active app development sites.'.PHP_EOL;
        }

        return
            $sites
                ->sortBy('hostname')
                ->map(function (AppDevSite $site): string {
                    $handler = $this->handler($site);

                    return <<<CADDY
                        https://{$site->hostname} {
                            bind 0.0.0.0
                            tls {$site->certificateDirectory()}/cert.pem {$site->certificateDirectory()}/key.pem
                            {$handler}
                        }
                        CADDY;
                })
                ->implode(PHP_EOL.PHP_EOL).PHP_EOL;
    }

    private function handler(AppDevSite $site): string
    {
        if ($site->unavailable) {
            return <<<'CADDY'
                header Cache-Control "no-store"
                header Content-Type "text/plain; charset=utf-8"
                respond "Orbit Route unavailable\n" 503
                CADDY;
        }

        if ($site->isProxy()) {
            $upstreams = implode(' ', array_map(
                static fn (string $address): string => "https://{$address}",
                $site->proxyAddresses(),
            ));

            return <<<CADDY
                reverse_proxy {$upstreams} {
                    header_up Host {$site->hostname}
                    transport http {
                        tls_server_name {$site->hostname}
                    }
                }
                CADDY;
        }

        $application = implode(PHP_EOL, array_filter([
            "root * {$site->checkoutPath}/{$site->documentRoot}",
            'encode zstd gzip',
            $site->phpVersion === null ? null : $this->phpHandler($site),
            'file_server',
        ]));

        if ($site->environment === 'production') {
            return $application;
        }

        return $this->withDevelopmentServer($application);
    }

    private function withDevelopmentServer(string $applicationHandler): string
    {
        $path = DevelopmentServerEndpoint::PATH;
        $upstream = DevelopmentServerEndpoint::upstream();

        return <<<CADDY
            @orbit_vite {
                path {$path} {$path}/*
            }
            handle @orbit_vite {
                uri strip_prefix {$path}
                reverse_proxy {$upstream}
            }
            handle {
                {$applicationHandler}
            }
            CADDY;
    }

    private function phpHandler(AppDevSite $site): string
    {
        if ($site->environment !== 'production') {
            return "php_fastcgi unix/{$site->socketPath()}";
        }

        return <<<CADDY
            php_fastcgi unix/{$site->socketPath()} {
                resolve_root_symlink
            }
            CADDY;
    }
}
