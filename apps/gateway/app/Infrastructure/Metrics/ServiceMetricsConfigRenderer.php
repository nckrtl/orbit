<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final readonly class ServiceMetricsConfigRenderer
{
    public const string CaddyPort = '9103';

    public const string FpmPort = '9114';

    public const string FpmVersion = 'v3.1.1';

    public const string Directory = '/etc/orbit/service-metrics';

    public const string FpmBinary = '/usr/local/bin/orbit-fpm-exporter';

    public const string FpmService = 'orbit-fpm-exporter';

    public const string Marker = '# Managed by Orbit: service-metrics';

    /** @var array<string, string> */
    public const array Checksums = [
        'x86_64' => '570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98',
        'aarch64' => 'd9773dfef3f6efbbbea0fe8be40ea79652d71ea24b20bfe89690ab026e205442',
    ];

    public function caddy(string $address, string $metricsAddress, bool $perHost = true): string
    {
        $this->guardAddress($address);
        $this->guardAddress($metricsAddress);
        $port = self::CaddyPort;
        $marker = self::Marker;
        $metrics = $perHost ? "metrics {\n        per_host\n    }" : "servers 0.0.0.0:443 {\n        metrics\n    }";

        return <<<CADDY
            {$marker}
            {
                {$metrics}
            }
            http://{$address}:{$port} {
                bind {$address}
                @scraper remote_ip {$metricsAddress}
                handle @scraper {
                    metrics /metrics
                }
                respond 403
            }

            CADDY;
    }

    public function fpm(ServiceMetricsNode $target): string
    {
        $this->guardAddress((string) $target->node->wireguard_ip);
        $pools = [];
        foreach ($target->instances as $instance) {
            $identity = ProductionPhpRuntimeIdentity::from($instance);
            $pools[] = [
                'socket' => 'unix://'.$identity->socket,
                'status_socket' => 'unix://'.$identity->socket.'.status',
                'status_path' => '/orbit-fpm-status',
                'config_path' => $identity->generatedDirectory.'/php-fpm.conf',
                'binary' => '/usr/sbin/php-fpm'.$identity->version,
                'cli_binary' => '/usr/bin/php'.$identity->version,
                'poll_interval' => '15s',
                'timeout' => '3s',
            ];
        }

        return self::Marker."\n".Yaml::dump([
            'monitor' => [
                'listen_addr' => $target->node->wireguard_ip.':'.self::FpmPort,
                'enable_json' => false,
                'scrape_timeout' => '10s',
            ],
            'php' => ['enabled' => false],
            'phpfpm' => ['enabled' => true, 'autodiscover' => false, 'poll_interval' => '15s', 'pools' => $pools],
            'laravel' => [],
            'logging' => ['level' => 'warn', 'format' => 'json'],
        ], 8, 2);
    }

    public function unit(): string
    {
        $marker = self::Marker;
        $binary = self::FpmBinary;
        $directory = self::Directory;

        return <<<UNIT
            {$marker}
            [Unit]
            Description=Orbit production PHP-FPM metrics
            After=network-online.target
            Wants=network-online.target

            [Service]
            ExecStart={$binary} serve --config {$directory}/fpm.yml
            Environment=TMPDIR=/run/orbit-fpm-exporter
            RuntimeDirectory=orbit-fpm-exporter
            RuntimeDirectoryMode=0755
            Restart=on-failure
            RestartSec=5
            NoNewPrivileges=true
            ProtectHome=read-only
            ProtectSystem=strict
            ReadWritePaths=/run/orbit-fpm-exporter /var/log/php-fpm.log
            UMask=0022

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }

    private function guardAddress(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Service metrics require a WireGuard IPv4 address.');
        }
    }
}
