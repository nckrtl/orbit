<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Nodes\ProvisionNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class ProvisionNodeCommand extends Command
{
    #[\Override]
    protected $signature = 'node:provision
        {name : Node name}
        {host : Public SSH host}
        {--ssh-port=22 : Public SSH port}
        {--ssh-user=root : Initial SSH user}
        {--role=* : Initial role assignment}
        {--host-key-fingerprint= : Approved SSH SHA256 host key fingerprint}
        {--wireguard-address= : Stable WireGuard address}
        {--wireguard-endpoint= : Per-node WireGuard endpoint override}
        {--dns-server= : Per-node DNS server override}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Provision or converge a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $repository->active();

        if ($profile === null) {
            $this->error('No active gateway profile.');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $host = $this->argument('host');
        $sshPort = $this->option('ssh-port');
        $sshUser = $this->option('ssh-user');
        $roles = $this->option('role');

        if (
            ! is_string($name)
            || ! is_string($host)
            || ! is_numeric($sshPort)
            || ! is_string($sshUser)
            || ! is_array($roles)
        ) {
            $this->error('Node arguments are invalid.');

            return self::FAILURE;
        }

        $roleNames = array_values(array_filter($roles, is_string(...)));
        $hostKeyFingerprint = $this->stringOption('host-key-fingerprint');

        if (! $this->validHostKeyFingerprint($hostKeyFingerprint)) {
            return self::FAILURE;
        }

        try {
            /** @var NodeResponse $node */
            $node = $connectors
                ->make($profile)
                ->send(new ProvisionNodeRequest(
                    name: $name,
                    publicSshHost: $host,
                    roles: $roleNames,
                    publicSshPort: (int) $sshPort,
                    sshUser: $sshUser,
                    wireguardAddress: $this->stringOption('wireguard-address'),
                    wireguardEndpointOverride: $this->stringOption('wireguard-endpoint'),
                    dnsServerOverride: $this->stringOption('dns-server'),
                    hostKeyFingerprint: $hostKeyFingerprint,
                ))
                ->dto();
        } catch (GatewayApiException $exception) {
            GatewayCommand::writeGatewayApiException($this, $exception);

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode($node->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Node [{$node->name}] is {$node->status}.");
        $this->line("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function validHostKeyFingerprint(?string $fingerprint): bool
    {
        if ($fingerprint === null) {
            return true;
        }

        if (preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/', $fingerprint) === 1) {
            return true;
        }

        $this->error('Host key fingerprint must use SSH SHA256 format: SHA256 followed by 43 base64 characters.');

        return false;
    }
}
