<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\SandboxSpec;

final readonly class UpCloudCloudInit
{
    public function render(SandboxSpec $spec): string
    {
        return "#cloud-config\n".json_encode([
            'users' => [[
                'name' => 'orbit', 'lock_passwd' => true, 'groups' => ['adm', 'sudo'],
                'sudo' => ['ALL=(ALL) NOPASSWD:ALL'], 'shell' => '/bin/bash',
                'ssh_authorized_keys' => [$spec->publicKey],
            ]],
            'ssh_pwauth' => false, 'disable_root' => true, 'package_update' => true,
            'packages' => ['wireguard-tools', 'git', 'curl', 'python3', 'acl', 'ripgrep'],
            'runcmd' => [['install', '-d', '-o', 'orbit', '-g', 'orbit', '-m', '0700', '/home/orbit/orbit'], [
                'sh', '-c', 'set -eu; if [ ! -e /swapfile ]; then fallocate -l 1G /swapfile; chmod 600 /swapfile; mkswap /swapfile; fi; swapon /swapfile; grep -q "^/swapfile " /etc/fstab || printf "/swapfile none swap sw 0 0\\n" >> /etc/fstab',
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return list<array<string, string>> */
    public function firewall(SandboxSpec $spec, bool $sealed = false): array
    {
        $rules = [
            ['direction' => 'in', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'tcp', 'source_address_start' => $spec->gatewayAddress, 'source_address_end' => $spec->gatewayAddress, 'destination_port_start' => '22', 'destination_port_end' => '22'],
            ['direction' => 'in', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'udp', 'source_address_start' => $spec->wireguardAddress, 'source_address_end' => $spec->wireguardAddress, 'destination_port_start' => (string) $spec->wireguardPort, 'destination_port_end' => (string) $spec->wireguardPort],
            ['direction' => 'in', 'action' => 'drop', 'family' => 'IPv4'],
            ['direction' => 'in', 'action' => 'drop', 'family' => 'IPv6'],
        ];
        if (! $sealed) {
            $rules[] = ['direction' => 'out', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'tcp', 'destination_address_start' => '169.254.169.254', 'destination_address_end' => '169.254.169.254', 'destination_port_start' => '80', 'destination_port_end' => '80'];
        }
        foreach ([['10.0.0.0', '10.255.255.255'], ['172.16.0.0', '172.31.255.255'], ['192.168.0.0', '192.168.255.255'], ['169.254.0.0', '169.254.255.255'], ['127.0.0.0', '127.255.255.255']] as [$start, $end]) {
            $rules[] = ['direction' => 'out', 'action' => 'drop', 'family' => 'IPv4', 'destination_address_start' => $start, 'destination_address_end' => $end];
        }
        $rules[] = ['direction' => 'out', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'udp', 'destination_address_start' => $spec->wireguardAddress, 'destination_address_end' => $spec->wireguardAddress, 'destination_port_start' => (string) $spec->wireguardPort, 'destination_port_end' => (string) $spec->wireguardPort];
        foreach (['80', '443'] as $port) {
            $rules[] = ['direction' => 'out', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'tcp', 'destination_port_start' => $port, 'destination_port_end' => $port];
        }
        foreach (['udp', 'tcp'] as $protocol) {
            $rules[] = ['direction' => 'out', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => $protocol, 'destination_port_start' => '53', 'destination_port_end' => '53'];
        }
        $rules[] = ['direction' => 'out', 'action' => 'drop', 'family' => 'IPv4'];
        $rules[] = ['direction' => 'out', 'action' => 'drop', 'family' => 'IPv6'];

        return $rules;
    }
}
