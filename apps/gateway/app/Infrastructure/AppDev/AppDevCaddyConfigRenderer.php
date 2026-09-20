<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AgentationEndpoint;
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
                    $internal = $this->localUnixSite($site);

                    $siteBlock = $site->publicListener
                        ? <<<CADDY
                            {$site->domain} {
                                bind 0.0.0.0
                                {$handler}
                            }
                            CADDY
                        : <<<CADDY
                            https://{$site->domain} {
                                bind 0.0.0.0
                                tls {$site->certificateDirectory()}/cert.pem {$site->certificateDirectory()}/key.pem
                                {$handler}
                            }
                            CADDY;

                    return $internal === null ? $siteBlock : $internal.PHP_EOL.PHP_EOL.$siteBlock;
                })
                ->implode(PHP_EOL.PHP_EOL).PHP_EOL;
    }

    private function localUnixSite(AppDevSite $site): ?string
    {
        if (! is_string($site->localUnixUpstream) || $site->localUnixUpstream === '') {
            return null;
        }

        $root = $site->checkoutPath === '' ? null : "root * {$site->checkoutPath}/{$site->documentRoot}";
        $php = $site->phpVersion === null ? null : $this->phpHandler($site);
        $application = implode(PHP_EOL, array_filter([$root, $php, $site->checkoutPath === '' ? null : 'file_server']));

        return <<<CADDY
            http://{$site->localUnixUpstream} {
                bind {$site->localUnixUpstream}
                {$application}
            }
            CADDY;
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

        if ($site->isAnalyticsTracking()) {
            return $this->analyticsTrackingHandler($site);
        }

        if ($site->isLocalHttpProxy()) {
            return <<<CADDY
                reverse_proxy {$site->localHttpUpstream} {
                    flush_interval -1
                }
                CADDY;
        }

        if ($site->isProxy()) {
            $upstreams = implode(' ', array_map(
                static fn (string $address): string => str_starts_with($address, 'unix/')
                    ? $address
                    : "https://{$address}",
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
            $hasRemoteHttps = array_any(
                $site->proxyAddresses(),
                static fn (string $address): bool => ! str_starts_with($address, 'unix/'),
            );
            $trust = $site->publicListener || $site->preserveForwardedIdentity || $hasRemoteHttps
                ? <<<CADDY

                        tls_trusted_ca_certs {$root}
                CADDY
                : '';
            $pool = count($site->proxyAddresses()) > 1
                ? <<<'CADDY'

                    lb_policy round_robin
                    lb_retries 0
                    fail_duration 10s
                CADDY
                : '';
            $unavailable = count($site->proxyAddresses()) > 1
                ? <<<'CADDY'

                handle_errors {
                    @orbit_unavailable `{err.status_code} == 502`
                    handle @orbit_unavailable {
                        header Cache-Control "no-store"
                        header Content-Type "text/plain; charset=utf-8"
                        respond "Orbit Route unavailable\n" 503
                    }
                }
                CADDY
                : '';

            return <<<CADDY
                reverse_proxy {$upstreams} {
                    header_up Host {$site->domain}{$identity}{$pool}
                    transport http {
                        tls_server_name {$site->domain}{$trust}
                    }
                }{$unavailable}
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

        $handlers = $this->withDevelopmentServer($application, $site->vitePort, $site->agentationPort);
        $wake = $this->hibernationWake($site);

        return $wake === null ? $handlers : $wake.PHP_EOL.$handlers;
    }

    /**
     * Plausible counts a visitor by the forwarded client address. A public listener already sees the
     * visitor; a Router behind a separate Ingress trusts that Ingress alone to name the visitor.
     */
    private function analyticsTrackingHandler(AppDevSite $site): string
    {
        $upstream = "reverse_proxy http://{$site->analyticsUpstream}";
        $proxy = $site->analyticsTrustedProxies === []
            ? ["    {$upstream}"]
            : [
                "    {$upstream} {",
                '        trusted_proxies '.implode(' ', $site->analyticsTrustedProxies),
                '    }',
            ];

        // The site template indents the first line only, so the following lines carry the site indentation.
        return implode(PHP_EOL.'    ', [
            'handle /js/* {',
            ...$proxy,
            '}',
            'handle /api/event {',
            ...$proxy,
            '}',
            'respond 404',
        ]);
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

        $probeHeader = RuntimeHibernation::ProbeHeader;

        // A probe (`orbit profile --instance`) measures this site without disturbing it: it must
        // not reach forward_auth, which would start the Processes hibernation halted, and it must
        // not reach the access log, whose mtime is the activity that decides when they halt
        // again. Every other request behaves exactly as before.
        return <<<CADDY
            @orbit_probe header {$probeHeader} 1
            log_skip @orbit_probe
            @orbit_asleep {
                not header {$probeHeader} 1
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

    private function withDevelopmentServer(string $applicationHandler, ?int $port, ?int $agentationPort): string
    {
        $path = DevelopmentServerEndpoint::PATH;
        $upstream = DevelopmentServerEndpoint::upstream($port);
        $rewrite = $port === null ? "uri strip_prefix {$path}" : '';
        $agentation = $this->agentationHandle($agentationPort);

        return <<<CADDY
            @orbit_vite {
                path {$path} {$path}/*
            }
            handle @orbit_vite {
                {$rewrite}
                reverse_proxy {$upstream}
            }
            {$agentation}handle {
                {$applicationHandler}
            }
            CADDY;
    }

    private function agentationHandle(?int $port): string
    {
        if ($port === null) {
            return '';
        }

        $path = AgentationEndpoint::PATH;
        $upstream = AgentationEndpoint::upstream($port);

        return <<<CADDY
            @orbit_agentation {
                path {$path} {$path}/*
            }
            handle @orbit_agentation {
                uri strip_prefix {$path}
                reverse_proxy {$upstream}
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
