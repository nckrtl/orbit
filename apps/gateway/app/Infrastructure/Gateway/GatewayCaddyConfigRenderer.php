<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

/**
 * Renders the Gateway site: Laravel owns its API, MCP, health, and well-known paths; `/grafana`
 * reaches the published Metrics site after the browser's own WireGuard authorization; every other
 * path serves the current web app release ([ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin)).
 *
 * The checkout path is a link to the current Gateway release. `resolve_root_symlink` resolves it for each
 * request, so PHP-FPM receives the release's real script path: a request that started before a release switch
 * finishes on the old release, and the next request runs the new one ([ADR 0201](/decisions/0201-release-the-gateway-automatically-from-green-main)).
 */
final readonly class GatewayCaddyConfigRenderer
{
    public const string ROOT_CA_PATH = '/etc/caddy/orbit-cert-current/root-ca.pem';

    public function render(string $hostname, string $wireguardIp, string $checkoutPath, string $webRoot): string
    {
        $rootCa = self::ROOT_CA_PATH;

        return <<<CADDYFILE
            {$hostname}, {$wireguardIp} {
                bind {$wireguardIp}
                tls /etc/caddy/orbit-cert-current/gateway.pem /etc/caddy/orbit-cert-current/gateway.key
                encode zstd gzip

                @gateway path /api/* /mcp /mcp/* /up /.well-known/*
                handle @gateway {
                    root * {$checkoutPath}/public
                    php_fastcgi unix//run/php/orbit-gateway.sock {
                        resolve_root_symlink
                        dial_timeout 10s
                        read_timeout 600s
                        write_timeout 600s
                        flush_interval 1ms
                    }
                }

                handle_path /grafana/* {
                    forward_auth unix//run/php/orbit-gateway.sock {
                        uri /api/v1/metrics/grafana/authorize
                        transport fastcgi {
                            env SCRIPT_FILENAME {$checkoutPath}/public/index.php
                            env SCRIPT_NAME /index.php
                            env REQUEST_URI /api/v1/metrics/grafana/authorize
                            env REMOTE_ADDR {remote_host}
                        }
                    }
                    reverse_proxy https://{$wireguardIp} {
                        header_up Host metrics.orbit
                        transport http {
                            tls_server_name metrics.orbit
                            tls_trusted_ca_certs {$rootCa}
                        }
                    }
                }

                handle {
                    root * {$webRoot}/current
                    @immutable path /assets/*
                    header @immutable Cache-Control "public, max-age=31536000, immutable"
                    @revalidate not path /assets/*
                    header @revalidate Cache-Control "no-cache"
                    try_files {path} /index.html
                    file_server
                }
            }
            CADDYFILE.PHP_EOL;
    }
}
