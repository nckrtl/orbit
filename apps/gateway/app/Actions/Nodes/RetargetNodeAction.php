<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\RetargetNodeData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;
use Throwable;

final readonly class RetargetNodeAction
{
    public function __construct(
        private HostKeyScanner $hostKeys,
        private KnownHostsStore $knownHosts,
        private SshKeyProvider $sshKeys,
        private SshExecutor $ssh,
        private WireGuardPeerConverger $wireGuard,
    ) {}

    public function execute(RetargetNodeData $data): Node
    {
        if (
            $data->publicSshHost === ''
            || filter_var($data->publicSshHost, FILTER_VALIDATE_IP) === false
            && filter_var($data->publicSshHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new NodeProvisioningException(
                'validation',
                'node.public_ssh_host_invalid',
                'The public SSH host is invalid.',
            );
        }

        if ($data->publicSshPort < 1 || $data->publicSshPort > 65535) {
            throw new NodeProvisioningException(
                'validation',
                'node.public_ssh_port_invalid',
                'The public SSH port is invalid.',
            );
        }

        $node = Node::query()->where('name', $data->name)->first();
        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
            throw new NodeProvisioningException(
                'lookup',
                'node.not_active',
                "Node [{$data->name}] does not exist as an active node.",
            );
        }

        try {
            $key = $this->hostKeys->scan($data->publicSshHost, $data->publicSshPort);
        } catch (Throwable $exception) {
            $node->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => 'ssh-host-key',
                'error_code' => 'node.ssh_host_key_scan_failed',
            ]);
            throw new NodeProvisioningException(
                'ssh-host-key',
                'node.ssh_host_key_scan_failed',
                "Could not scan the SSH host key for node [{$node->name}].",
                $exception,
            );
        }
        if ($node->ssh_host_fingerprint === null || ! hash_equals($node->ssh_host_fingerprint, $key->fingerprint)) {
            $node->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => 'ssh-host-key',
                'error_code' => 'node.ssh_host_key_mismatch',
            ]);
            throw new NodeProvisioningException(
                'ssh-host-key',
                'node.ssh_host_key_mismatch',
                "The SSH host fingerprint did not match for node [{$node->name}].",
            );
        }

        $oldHost = $node->public_ssh_host;
        $oldPort = $node->public_ssh_port;
        $node->update([
            'status' => LifecycleStatus::Provisioning,
            'public_ssh_host' => $data->publicSshHost,
            'public_ssh_port' => $data->publicSshPort,
            'failed_step' => null,
            'error_code' => null,
        ]);

        try {
            $this->knownHosts->put($data->publicSshHost, $data->publicSshPort, $key);
            $connection = new SshConnection(
                $data->publicSshHost,
                $node->user,
                $data->publicSshPort,
                $this->sshKeys->privateKeyPath(),
                $this->knownHosts->path(),
            );
            $this->wireGuard->converge($node, $connection);
            if (! is_string($node->wireguard_address) || $node->wireguard_address === '') {
                throw new NodeProvisioningException(
                    'wireguard-address',
                    'vpn.peer_address_missing',
                    "Node [{$node->name}] has no WireGuard address.",
                );
            }
            $private = $this->ssh->execute(
                new SshConnection(
                    $node->wireguard_address,
                    $node->user,
                    22,
                    $this->sshKeys->privateKeyPath(),
                    $this->knownHosts->path(),
                ),
                new RemoteCommand(['true']),
            );
            if (! $private->succeeded()) {
                throw new NodeProvisioningException(
                    'wireguard-ssh',
                    'vpn.peer_ssh_failed',
                    "Could not reach node [{$node->name}] through WireGuard.",
                    result: $private,
                );
            }
            $this->knownHosts->put($node->wireguard_address, 22, $key);
            $node->update(['status' => LifecycleStatus::Active]);
        } catch (Throwable $exception) {
            $failure = $exception instanceof NodeProvisioningException
                ? $exception
                : new NodeProvisioningException(
                    'retarget',
                    'node.retarget_failed',
                    "Could not retarget node [{$node->name}].",
                    $exception,
                );
            $node->update([
                'status' => LifecycleStatus::Failed,
                'public_ssh_host' => $oldHost,
                'public_ssh_port' => $oldPort,
                'failed_step' => $failure->step,
                'error_code' => $failure->errorCode,
            ]);
            throw $failure;
        }

        return $node->refresh();
    }
}
