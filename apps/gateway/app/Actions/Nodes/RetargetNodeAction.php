<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\RetargetNodeData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;
use Throwable;

/**
 * Retarget an active node to a new public SSH host.
 *
 * A node without role rows still exposes the public SSH recovery rule, so
 * the retarget pins the host key on the new public address and rewrites the
 * node's WireGuard peer over public SSH. Role convergence removes that rule,
 * so a node with any role row is reachable only through WireGuard. Its
 * retarget pins the host key over the tunnel and only updates the public
 * record; the node-side WireGuard endpoint must already point at the Gateway.
 *
 * @mago-expect lint:cyclomatic-complexity Ordered validation, identity, and rollback gates stay in one action.
 */
final readonly class RetargetNodeAction
{
    public const string VPN_RECOVERY_HINT =
        'The node is reachable only through WireGuard after role provisioning. '
            .'On the node, as root, set "Endpoint" in /etc/wireguard/orbit.conf to the Gateway address, '
            .'run "systemctl restart wg-quick@orbit", then retry the retarget.';

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

        return $node->roles()->exists()
            ? $this->retargetOverWireGuard($node, $data)
            : $this->retargetOverPublicSsh($node, $data);
    }

    private function retargetOverPublicSsh(Node $node, RetargetNodeData $data): Node
    {
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
        $this->requirePinned($node, $key);

        return $this->commit($node, $data, function (Node $node, RetargetNodeData $data) use ($key): void {
            $this->knownHosts->put($data->publicSshHost, $data->publicSshPort, $key);
            $this->wireGuard->converge($node, new SshConnection(
                $data->publicSshHost,
                $node->user,
                $data->publicSshPort,
                $this->sshKeys->privateKeyPath(),
                $this->knownHosts->path(),
            ));
            $address = $this->wireGuardAddress($node);
            $this->probeWireGuard($node, $address);
            $this->knownHosts->put($address, 22, $key);
        });
    }

    private function retargetOverWireGuard(Node $node, RetargetNodeData $data): Node
    {
        try {
            $address = $this->wireGuardAddress($node);
        } catch (NodeProvisioningException $exception) {
            $node->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => $exception->step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }

        try {
            $key = $this->hostKeys->scan($address, 22);
        } catch (Throwable $exception) {
            // The tunnel must be repaired on the node first, so the record stays active and unchanged.
            throw new NodeProvisioningException(
                'wireguard-ssh',
                'node.retarget_requires_vpn',
                "Could not reach node [{$node->name}] through WireGuard. ".self::VPN_RECOVERY_HINT,
                $exception,
            );
        }
        $this->requirePinned($node, $key);

        return $this->commit($node, $data, function (Node $node, RetargetNodeData $data) use ($address, $key): void {
            $this->knownHosts->put($address, 22, $key);
            $this->probeWireGuard($node, $address);
            $this->knownHosts->put($data->publicSshHost, $data->publicSshPort, $key);
        });
    }

    private function requirePinned(Node $node, HostKey $key): void
    {
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
    }

    /** @param \Closure(Node, RetargetNodeData): void $verify */
    private function commit(Node $node, RetargetNodeData $data, \Closure $verify): Node
    {
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
            $verify($node, $data);
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

    private function wireGuardAddress(Node $node): string
    {
        if (! is_string($node->wireguard_address) || $node->wireguard_address === '') {
            throw new NodeProvisioningException(
                'wireguard-address',
                'vpn.peer_address_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return $node->wireguard_address;
    }

    private function probeWireGuard(Node $node, string $address): void
    {
        $private = $this->ssh->execute(
            new SshConnection(
                $address,
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
    }
}
