<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class NodeExporterRenderer
{
    public function environment(string $wireguardAddress): string
    {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('The exporter address must be an IPv4 address.');
        }

        return "ARGS=--web.listen-address={$wireguardAddress}:9100\n";
    }

    public function unit(): string
    {
        return "[Unit]\nDescription=Orbit node exporter (systemd)\nAfter=network-online.target\n[Service]\nEnvironmentFile=-/etc/default/prometheus-node-exporter\nExecStart=/usr/bin/prometheus-node-exporter \${ARGS}\nRestart=on-failure\n[Install]\nWantedBy=multi-user.target\n";
    }

    /** @return array{name:string,owner:string,protocol:string,port:int,interface:string,source:string,action:string} */
    public function firewallIntent(string $metricsAddress, string $interface = 'wg0'): array
    {
        $this->validateAddress($metricsAddress);
        if ($interface !== 'wg0') {
            throw new \InvalidArgumentException('The exporter interface must be wg0.');
        }

        return [
            'name' => 'orbit-metrics-node-exporter',
            'owner' => 'metrics',
            'protocol' => 'tcp',
            'port' => 9100,
            'interface' => $interface,
            'source' => $metricsAddress,
            'action' => 'allow',
        ];
    }

    private function validateAddress(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('The exporter address must be an IPv4 address.');
        }
    }
}
