<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliProcess;

/** Renders the Caddy site that terminates Orbit-CA TLS for the collector hostname and reverse-proxies to the loopback collector. */
final readonly class ProxyCliCaddySiteRenderer
{
    public function render(int $port = ProxyCliProcess::PORT): string
    {
        $marker = ProxyCliFootprint::CaddyFragmentMarker;
        $host = ProxyCliFootprint::Hostname;
        $certificate = ProxyCliFootprint::CertificateCurrentDirectory;
        $bind = ProxyCliFootprint::CaddyBindPlaceholder;

        return <<<CADDY
            {$marker}
            {$host} {
                bind {$bind}
                tls {$certificate}/proxycli.pem {$certificate}/proxycli.key
                reverse_proxy 127.0.0.1:{$port}
            }
            CADDY;
    }
}
