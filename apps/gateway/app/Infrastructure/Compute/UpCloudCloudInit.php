<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\SandboxSpec;

final readonly class UpCloudCloudInit
{
    /** Creates the ZFS pool in the disk space after root and mounts it at the checkout directory. */
    private const string ZfsPool = <<<'SH'
        set -eu
        if ! zpool list orbit >/dev/null 2>&1; then
            sgdisk -e /dev/vda
            sgdisk -n 0:0:0 -t 0:bf01 -c 0:orbit-zfs /dev/vda
            partprobe /dev/vda
            udevadm settle
            zpool create -f -o ashift=12 -O compression=lz4 -O atime=off -O acltype=posixacl -O xattr=sa -O mountpoint=none orbit /dev/disk/by-partlabel/orbit-zfs
            zfs create -o mountpoint=/home/orbit/orbit orbit/checkout
        fi
        chown orbit:orbit /home/orbit/orbit
        chmod 0700 /home/orbit/orbit
        SH;

    public function render(SandboxSpec $spec): string
    {
        $config = [
            'users' => [[
                'name' => 'orbit', 'lock_passwd' => true, 'groups' => ['adm', 'sudo'],
                'sudo' => ['ALL=(ALL) NOPASSWD:ALL'], 'shell' => '/bin/bash',
                'ssh_authorized_keys' => [$spec->publicKey],
            ]],
            'ssh_pwauth' => false, 'disable_root' => true,
        ];
        $runcmd = [['install', '-d', '-o', 'orbit', '-g', 'orbit', '-m', '0700', '/home/orbit/orbit'], [
            'sh', '-c', 'set -eu; if [ ! -e /swapfile ]; then fallocate -l 1G /swapfile; chmod 600 /swapfile; mkswap /swapfile; fi; swapon /swapfile 2>/dev/null || swapon --show=NAME --noheadings | grep -qx /swapfile; grep -q "^/swapfile " /etc/fstab || printf "/swapfile none swap sw 0 0\\n" >> /etc/fstab',
        ]];
        if ($spec->usesBaseImage()) {
            // The base template already has the packages. Root keeps its template size; the rest of the disk holds the ZFS pool.
            $config += ['package_update' => false, 'growpart' => ['mode' => 'off'], 'resize_rootfs' => false, 'runcmd' => [...$runcmd, ['sh', '-c', self::ZfsPool]]];
        } else {
            $config += ['package_update' => true, 'packages' => ['wireguard-tools', 'git', 'gh', 'curl', 'python3', 'acl', 'ripgrep'], 'runcmd' => $runcmd];
        }

        return "#cloud-config\n".json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** The base template build VM (ADR 0204): the Gateway key only; the setup script installs every package. */
    public function renderImageBuild(string $publicKey): string
    {
        $config = [
            'users' => [[
                'name' => 'orbit', 'lock_passwd' => true, 'groups' => ['adm', 'sudo'],
                'sudo' => ['ALL=(ALL) NOPASSWD:ALL'], 'shell' => '/bin/bash',
                'ssh_authorized_keys' => [$publicKey],
            ]],
            'ssh_pwauth' => false, 'disable_root' => true, 'package_update' => false,
        ];

        return "#cloud-config\n".json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return list<array<string, string>> */
    public function firewall(SandboxSpec $spec, bool $sealed = false): array
    {
        return $this->rules($spec->gatewayAddress, [$spec->wireguardAddress, (string) $spec->wireguardPort], $sealed);
    }

    /**
     * The base template build VM admits SSH from the Gateway only and has no WireGuard hub.
     *
     * @return list<array<string, string>>
     */
    public function imageBuildFirewall(string $gatewayAddress): array
    {
        return $this->rules($gatewayAddress, null, false);
    }

    /**
     * @param  array{string, string}|null  $wireguard
     * @return list<array<string, string>>
     */
    private function rules(string $gatewayAddress, ?array $wireguard, bool $sealed): array
    {
        $rules = [['direction' => 'in', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'tcp', 'source_address_start' => $gatewayAddress, 'source_address_end' => $gatewayAddress, 'destination_port_start' => '22', 'destination_port_end' => '22']];
        if ($wireguard !== null) {
            $rules[] = ['direction' => 'in', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'udp', 'source_address_start' => $wireguard[0], 'source_address_end' => $wireguard[0], 'destination_port_start' => $wireguard[1], 'destination_port_end' => $wireguard[1]];
        }
        $rules[] = ['direction' => 'in', 'action' => 'drop', 'family' => 'IPv4'];
        $rules[] = ['direction' => 'in', 'action' => 'drop', 'family' => 'IPv6'];
        if (! $sealed) {
            $rules[] = ['direction' => 'out', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'tcp', 'destination_address_start' => '169.254.169.254', 'destination_address_end' => '169.254.169.254', 'destination_port_start' => '80', 'destination_port_end' => '80'];
        }
        foreach ([['10.0.0.0', '10.255.255.255'], ['172.16.0.0', '172.31.255.255'], ['192.168.0.0', '192.168.255.255'], ['169.254.0.0', '169.254.255.255'], ['127.0.0.0', '127.255.255.255']] as [$start, $end]) {
            $rules[] = ['direction' => 'out', 'action' => 'drop', 'family' => 'IPv4', 'destination_address_start' => $start, 'destination_address_end' => $end];
        }
        if ($wireguard !== null) {
            $rules[] = ['direction' => 'out', 'action' => 'accept', 'family' => 'IPv4', 'protocol' => 'udp', 'destination_address_start' => $wireguard[0], 'destination_address_end' => $wireguard[0], 'destination_port_start' => $wireguard[1], 'destination_port_end' => $wireguard[1]];
        }
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

    /**
     * Compares provider rules with the expected ones, ignoring the provider's position and comment fields.
     *
     * @param  list<array<string, string>>  $expected
     */
    public function sameFirewall(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $rule) {
            if (! is_array($actual[$index] ?? null)) {
                return false;
            }
            $normalized = array_filter($actual[$index], fn (mixed $value, string $key): bool => ! in_array($key, ['position', 'comment'], true) && $value !== '', ARRAY_FILTER_USE_BOTH);
            ksort($normalized);
            ksort($rule);
            if ($normalized !== $rule) {
                return false;
            }
        }

        return true;
    }
}
