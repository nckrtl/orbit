<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliProcess;

/**
 * Renders the Caddy site that terminates Orbit-CA TLS for the collector hostname and reverse-proxies to the loopback
 * collector. Caddy retries the collector for a few seconds when it cannot connect, so a request that arrives while
 * enable restarts the collector waits for it instead of failing.
 */
final readonly class ProxyCliCaddySiteRenderer
{
    public const string RetryDuration = '5s';

    public function render(int $port = ProxyCliProcess::PORT): string
    {
        $marker = ProxyCliFootprint::CaddyFragmentMarker;
        $host = ProxyCliFootprint::Hostname;
        $certificate = ProxyCliFootprint::CertificateCurrentDirectory;
        $bind = ProxyCliFootprint::CaddyBindPlaceholder;
        $retry = self::RetryDuration;

        return <<<CADDY
            {$marker}
            {$host} {
                bind {$bind}
                tls {$certificate}/proxycli.pem {$certificate}/proxycli.key
                reverse_proxy 127.0.0.1:{$port} {
                    lb_try_duration {$retry}
                }
            }
            CADDY;
    }
}
