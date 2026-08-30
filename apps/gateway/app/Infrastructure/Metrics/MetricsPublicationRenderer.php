<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class MetricsPublicationRenderer
{
    public function caddy(
        string $metricsAddress,
        ?string $gatewayAddress = null,
        string $certificatePath = '/etc/caddy/orbit-metrics-cert-current/metrics.pem',
        string $privateKeyPath = '/etc/caddy/orbit-metrics-cert-current/metrics.key',
    ): string {
        $this->validateAddress($metricsAddress);
        $gatewayAddress ??= $metricsAddress;
        $this->validateAddress($gatewayAddress);
        $this->validatePath($certificatePath);
        $this->validatePath($privateKeyPath);

        return "# Managed by Orbit: metrics\nmetrics.orbit {$gatewayAddress}:443 {\n  bind {$gatewayAddress}\n  tls {$certificatePath} {$privateKeyPath}\n  reverse_proxy http://{$metricsAddress}:3000\n}\n";
    }

    private function validateAddress(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('Metrics publication addresses must be IPv4 addresses.');
        }
    }

    private function validatePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/[\r\n]/', $path) === 1
            || ! str_starts_with($path, '/etc/caddy/')
        ) {
            throw new \InvalidArgumentException('Metrics certificate paths must be absolute Caddy paths.');
        }
    }
}
