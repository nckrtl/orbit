<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\NodeSettingOptions;
use Orbit\Sdk\Requests\Nodes\ProvisionNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

/** @mago-expect lint:cyclomatic-complexity,halstead Provision keeps closed setting and network parsing beside the identity gates. */
final class ProvisionNodeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:provision
        {name : Node name}
        {host? : Optional public SSH host}
        {--ssh-port=22 : Public SSH port}
        {--user=root : Initial SSH user}
        {--orbit-user= : Orbit-managed system user; defaults to orbit for a new node}
        {--platform=linux : Node platform (linux only)}
        {--architecture= : Node machine architecture}
        {--tld= : Unique development TLD for app-dev}
        {--role=* : Initial role assignment}
        {--host-key-fingerprint= : Approved SSH SHA256 host key fingerprint}
        {--cluster= : Optional numeric Cluster ID}
        {--wireguard-ip= : Stable WireGuard IP address}
        {--wireguard-address= : Deprecated alias for --wireguard-ip}
        {--lan-ip= : Optional Cluster-local LAN IPv4 address}
        {--wireguard-endpoint= : Per-node WireGuard endpoint override}
        {--dns-server= : Per-node DNS server override}
        {--setting=* : Repeatable node setting as setting-path:value}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Provision or converge a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $name = $this->argument('name');
        $host = $this->argument('host');
        $sshPort = $this->option('ssh-port');
        $user = $this->option('user');
        $roles = $this->option('role');

        if (
            ! is_string($name)
            || ! is_string($host)
            && $host !== null
            || ! is_string($user)
            || ! is_array($roles)
        ) {
            return $this->renderGatewayFailure(
                'node.arguments_invalid',
                'Node arguments are invalid.',
            );
        }

        if (
            ! is_string($sshPort)
            || preg_match('/\A[1-9]\d{0,4}\z/D', $sshPort) !== 1
            || (int) $sshPort > 65_535
        ) {
            return $this->renderGatewayFailure(
                'node.ssh_port_invalid',
                'SSH port must be an integer from 1 to 65535.',
            );
        }

        $roleNames = array_values(array_filter($roles, is_string(...)));
        $platform = $this->stringOption('platform');
        $hostKeyFingerprint = $this->stringOption('host-key-fingerprint');

        if ($platform !== 'linux') {
            return $this->renderGatewayFailure(
                'node.platform_invalid',
                'Platform must be linux.',
            );
        }

        if (! $this->validHostKeyFingerprint($hostKeyFingerprint)) {
            return self::FAILURE;
        }

        $clusterId = null;
        $cluster = $this->option('cluster');

        if ($cluster !== null) {
            if (! is_string($cluster) || preg_match('/\A[1-9]\d*\z/D', $cluster) !== 1) {
                return $this->renderGatewayFailure(
                    'cluster.id_invalid',
                    'Cluster ID must be a positive integer.',
                );
            }

            $clusterId = (int) $cluster;
        }

        $canonicalWireguardIp = $this->option('wireguard-ip');
        $legacyWireguardIp = $this->option('wireguard-address');

        if (
            $canonicalWireguardIp !== null
            && ! is_string($canonicalWireguardIp)
            || $legacyWireguardIp !== null
            && ! is_string($legacyWireguardIp)
        ) {
            return $this->renderGatewayFailure(
                'node.wireguard_ip_invalid',
                'WireGuard IP must be an IPv4 address.',
            );
        }

        if (
            is_string($canonicalWireguardIp)
            && is_string($legacyWireguardIp)
            && $canonicalWireguardIp !== $legacyWireguardIp
        ) {
            return $this->renderGatewayFailure(
                'node.wireguard_ip_conflict',
                'WireGuard options must match when both are supplied.',
            );
        }

        $wireguardIp = is_string($canonicalWireguardIp) ? $canonicalWireguardIp : $legacyWireguardIp;

        if (is_string($wireguardIp) && filter_var($wireguardIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return $this->renderGatewayFailure(
                'node.wireguard_ip_invalid',
                'WireGuard IP must be an IPv4 address.',
            );
        }

        $lanIp = $this->option('lan-ip');

        if (
            $lanIp !== null
            && (! is_string($lanIp)
            || filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
        ) {
            return $this->renderGatewayFailure(
                'node.lan_ip_invalid',
                'LAN IP must be an IPv4 address.',
            );
        }

        $settings = NodeSettingOptions::parse($this->option('setting'));

        if ($settings['ok'] === false) {
            return $this->renderGatewayFailure($settings['code'], $settings['message']);
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $node = $this->send(
            $connector,
            new ProvisionNodeRequest(
                name: $name,
                publicSshHost: $host,
                roles: $roleNames,
                publicSshPort: (int) $sshPort,
                user: $user,
                orbitUser: $this->stringOption('orbit-user'),
                clusterId: $clusterId,
                wireguardIp: is_string($wireguardIp) ? $wireguardIp : null,
                lanIp: is_string($lanIp) ? $lanIp : null,
                wireguardEndpointOverride: $this->stringOption('wireguard-endpoint'),
                dnsServerOverride: $this->stringOption('dns-server'),
                hostKeyFingerprint: $hostKeyFingerprint,
                platform: $platform,
                architecture: $this->stringOption('architecture'),
                tld: $this->stringOption('tld'),
                settingsProvided: $settings['provided'],
                settings: $settings['provided'] ? NodeSettingOptions::settings($settings['body']) : null,
            ),
            NodeResponse::class,
        );

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->info("Node [{$node->name}] is {$node->status}.");
        $this->line("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }

    private function validHostKeyFingerprint(?string $fingerprint): bool
    {
        if ($fingerprint === null) {
            return true;
        }

        if (preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/', $fingerprint) === 1) {
            return true;
        }

        $this->renderGatewayFailure(
            'node.host_key_fingerprint_invalid',
            'Host key fingerprint must use SSH SHA256 format: SHA256 followed by 43 base64 characters.',
        );

        return false;
    }
}
