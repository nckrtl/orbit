<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Infrastructure\Gateway\GatewayApplicationPath;

final readonly class MetricsPublicationRenderer
{
    private string $gatewayCheckoutPath;

    /** Without a path, the checkout that holds this file. Guest scripts render with it outside Laravel. */
    public function __construct(?string $gatewayCheckoutPath = null)
    {
        $this->gatewayCheckoutPath = $gatewayCheckoutPath ?? dirname(__DIR__, 3);
    }

    /**
     * The renderer for the running Gateway. It names the stable Gateway application path, not this
     * file's directory: in the release layout that directory is one release, which a later deploy prunes.
     */
    public static function forGateway(): self
    {
        return new self(GatewayApplicationPath::resolve());
    }

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
        $this->validateCheckoutPath($this->gatewayCheckoutPath);

        return
            "# Managed by Orbit: metrics\n# Orbit Metrics authorization: 1\nmetrics.orbit {\n  bind {$gatewayAddress}\n  tls {$certificatePath} {$privateKeyPath}\n  forward_auth unix//run/php/orbit-gateway.sock {\n    uri /index.php\n    transport fastcgi {\n      root {$this->gatewayCheckoutPath}/public\n      resolve_root_symlink\n      split .php\n      env REQUEST_URI /api/v1/metrics/grafana/authorize\n      env REMOTE_ADDR {remote_host}\n    }\n  }\n  reverse_proxy http://{$metricsAddress}:"
            .MetricsFootprint::PublicationPort
            ."\n}\n";
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

    private function validateCheckoutPath(string $path): void
    {
        if (
            $path === ''
            || ! str_starts_with($path, '/')
            || str_contains($path, "\0")
            || preg_match('/[\r\n{}]/', $path) === 1
        ) {
            throw new \InvalidArgumentException('Gateway checkout path must be an absolute path.');
        }
    }
}
