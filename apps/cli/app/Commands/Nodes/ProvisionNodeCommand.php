<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\ProvisionNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

/** @mago-expect lint:cyclomatic-complexity The command validates the frozen enrollment transport shape. */
final class ProvisionNodeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:provision
        {name : Node name}
        {host? : Optional public SSH host}
        {--ssh-port=22 : Public SSH port}
        {--ssh-user=root : Initial SSH user}
        {--platform=linux : Node platform (linux or darwin)}
        {--architecture= : Node machine architecture}
        {--tld= : Unique development TLD for app-dev}
        {--role=* : Initial role assignment}
        {--host-key-fingerprint= : Approved SSH SHA256 host key fingerprint}
        {--wireguard-address= : Stable WireGuard address}
        {--wireguard-public-key= : Public key for an already installed WireGuard tunnel}
        {--wireguard-endpoint= : Per-node WireGuard endpoint override}
        {--dns-server= : Per-node DNS server override}
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
        $sshUser = $this->option('ssh-user');
        $roles = $this->option('role');

        if (
            ! is_string($name)
            || ! is_string($host)
            && $host !== null
            || ! is_string($sshUser)
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
        $wireguardPublicKey = $this->option('wireguard-public-key');

        if (! in_array(needle: $platform, haystack: ['linux', 'darwin'], strict: true)) {
            return $this->renderGatewayFailure(
                'node.platform_invalid',
                'Platform must be linux or darwin.',
            );
        }

        if (! $this->validHostKeyFingerprint($hostKeyFingerprint)) {
            return self::FAILURE;
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
                sshUser: $sshUser,
                wireguardAddress: $this->stringOption('wireguard-address'),
                wireguardEndpointOverride: $this->stringOption('wireguard-endpoint'),
                dnsServerOverride: $this->stringOption('dns-server'),
                hostKeyFingerprint: $hostKeyFingerprint,
                platform: $platform,
                architecture: $this->stringOption('architecture'),
                tld: $this->stringOption('tld'),
                wireguardPublicKey: is_string($wireguardPublicKey) ? $wireguardPublicKey : null,
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
