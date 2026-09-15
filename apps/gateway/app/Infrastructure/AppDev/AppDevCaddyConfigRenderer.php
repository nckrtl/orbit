<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\Hibernation\RuntimeHibernation;
use Illuminate\Support\Collection;

final readonly class AppDevCaddyConfigRenderer
{
    public const string ORBIT_ROOT_CA_PATH = '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt';

    public function __construct(
        private string $gatewayOrigin = 'https://gateway.orbit',
    ) {}

    /** @param Collection<int, AppDevSite> $sites */
    public function render(Collection $sites): string
    {
        if ($sites->isEmpty()) {
            return '# Orbit has no active app development sites.'.PHP_EOL;
        }

        return
            $sites
                ->sortBy('domain')
                ->map(function (AppDevSite $site): string {
                    $handler = $this->handler($site);

                    $scheme = $site->publicListener ? '' : 'https://';

                    return <<<CADDY
                        {$scheme}{$site->domain} {
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
            $identity = $site->preserveForwardedIdentity
                ? <<<CADDY

                    header_up X-Forwarded-Proto https
                    header_up X-Forwarded-For {remote_host}
                    header_up X-Forwarded-Host {$site->domain}
                CADDY
                : '';
            $root = self::ORBIT_ROOT_CA_PATH;
            $trust = $site->publicListener || $site->preserveForwardedIdentity
                ? <<<CADDY

                        tls_trusted_ca_certs {$root}
                CADDY
                : '';

            return <<<CADDY
                reverse_proxy {$upstreams} {
                    header_up Host {$site->domain}{$identity}
                    transport http {
                        tls_server_name {$site->domain}{$trust}
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

        $handlers = $this->withDevelopmentServer($application);
        $wake = $this->hibernationWake($site);

        return $wake === null ? $handlers : $wake.PHP_EOL.$handlers;
    }

    private function hibernationWake(AppDevSite $site): ?string
    {
        if (preg_match('/\Aapp-instance-([1-9][0-9]*)\z/D', $site->scope, $matches) !== 1) {
            return null;
        }

        $id = $matches[1];
        $key = RuntimeHibernation::key((int) $id);
        $log = RuntimeHibernation::accessLogPath($key);
        $host = parse_url($this->gatewayOrigin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $uri = '/api/v1/runtime-activations/app-instance/'.$id;
        $root = self::ORBIT_ROOT_CA_PATH;
        $markers = RuntimeHibernation::MarkerDirectory;
        $marker = '/'.$key.'.awake';

        return <<<CADDY
            @orbit_asleep {
                not file {
                    root {$markers}
                    try_files {$marker}
                }
            }
            handle @orbit_asleep {
                forward_auth {$this->gatewayOrigin} {
                    uri {$uri}
                    header_up Host {$host}
                    transport http {
                        tls_trusted_ca_certs {$root}
                        tls_server_name {$host}
                    }
                }
            }
            log {
                output file {$log}
            }
            CADDY;
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
