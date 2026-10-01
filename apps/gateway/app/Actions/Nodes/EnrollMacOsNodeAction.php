<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\MacOsEnrollmentObservation;
use App\Domain\Nodes\NodeArchitectureMismatchException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Infrastructure\Nodes\MacOsNodeConverger;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Models\Node;
use Throwable;

/**
 * Enrolls a roleless Mac in place and leaves Linux provisioning untouched.
 */
final readonly class EnrollMacOsNodeAction
{
    public function __construct(
        private MacOsNodeConverger $converger,
        private KnownHostsStore $knownHosts,
        private WireGuardAddressAllocator $addresses,
    ) {}

    public function execute(Node $node, ProvisionNodeData $data): Node
    {
        $existed = $node->exists;
        $this->refuseUnsupportedChanges($node, $data);
        $account = $this->account($node, $data);
        $address = $this->address($node, $data);
        [$host, $port] = $this->sshTarget($node, $data);

        try {
            $observation = $this->converger->enroll(
                name: $node->name,
                host: $host,
                port: $port,
                account: $account,
                address: $address,
                storedFingerprint: $this->pinnedFingerprint($node),
                expectedFingerprint: $data->expectedSshHostFingerprint,
            );
            $architecture = $this->architecture($node, $data, $observation);
        } catch (NodeProvisioningException $exception) {
            $this->recordRemoteFailure($node, $existed, $host, $port, $account, $address, $exception);

            throw $this->httpFailure($exception);
        } catch (SshHostKeyScanException $exception) {
            $failure = new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$node->name}].",
                previous: $exception,
                result: $exception->result,
            );
            $this->recordRemoteFailure($node, $existed, $host, $port, $account, $address, $failure);

            throw $failure;
        } catch (NodeArchitectureMismatchException $exception) {
            $failure = new NodeProvisioningException(
                step: 'machine-architecture',
                errorCode: NodeArchitectureMismatchException::ERROR_CODE,
                message: $exception->getMessage(),
                previous: $exception,
            );
            $this->recordRemoteFailure($node, $existed, $host, $port, $account, $address, $failure);

            throw $this->httpFailure($failure);
        }

        try {
            $this->pin($host, $port, $address, $observation->hostKey);
        } catch (Throwable $exception) {
            $failure = new NodeProvisioningException(
                step: 'ssh-pin',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not pin the SSH host key for node [{$node->name}].",
                previous: $exception,
            );
            $this->recordPinFailure($node, $existed, $host, $port, $account, $address, $architecture, $failure);

            throw $failure;
        }

        $this->persist($node, $host, $port, $account, $address, $architecture, $observation->hostKey);

        return $node->refresh()->load('roles');
    }

    private function account(Node $node, ProvisionNodeData $data): string
    {
        if ($data->user === null || $data->orbitUser === null) {
            throw new ResourceOperationException(
                errorCode: 'node.macos_account_required',
                message: 'macOS enrollment requires the existing account in user and orbit_user.',
            );
        }

        if ($data->user !== $data->orbitUser) {
            throw new ResourceOperationException(
                errorCode: 'node.macos_account_mismatch',
                message: 'macOS enrollment requires user and orbit_user to name the same account.',
            );
        }

        if (! LinuxUserName::isValid($data->user)) {
            throw new ResourceOperationException(
                errorCode: 'node.invalid_linux_user',
                message: 'The node Linux user name is invalid.',
            );
        }

        if ($this->pinned($node) && $node->user !== $data->user) {
            throw new ResourceOperationException(
                errorCode: 'node.user_change_unsupported',
                message: "Node [{$node->name}] cannot change its managed account after macOS enrollment.",
                status: 409,
            );
        }

        return $data->user;
    }

    private function address(Node $node, ProvisionNodeData $data): string
    {
        $recorded = $node->exists ? $this->stringOrNull($node->wireguard_ip) : null;
        $recorded = $recorded === '' ? null : $recorded;
        $requested = $data->wireguardIp === '' ? null : $data->wireguardIp;

        if ($recorded !== null && $requested !== null && $recorded !== $requested) {
            throw new ResourceOperationException(
                errorCode: 'node.wireguard_required',
                message: "Node [{$node->name}] keeps WireGuard address [{$recorded}]; macOS enrollment does not replace it.",
                status: 409,
                details: ['step' => 'identity'],
            );
        }

        $address = $recorded ?? $requested;

        if ($address === null) {
            throw new ResourceOperationException(
                errorCode: 'node.wireguard_required',
                message: 'macOS enrollment requires a WireGuard address that is already on the machine.',
                status: 409,
                details: ['step' => 'identity'],
            );
        }

        $this->addresses->forProvisioning($address, $node->exists ? $node : null);

        if (! $this->pinned($node) && $data->expectedSshHostFingerprint === null) {
            throw new ResourceOperationException(
                errorCode: 'node.ssh_host_fingerprint_required',
                message: "An expected SSH host fingerprint is required for node [{$node->name}].",
            );
        }

        return $address;
    }

    private function refuseUnsupportedChanges(Node $node, ProvisionNodeData $data): void
    {
        if ($data->platform === 'macos' && $node->exists && $node->platform !== 'macos' && $this->pinned($node)) {
            throw new ResourceOperationException(
                errorCode: 'node.platform_unsupported',
                message: "Node [{$node->name}] is already managed and cannot be enrolled as macOS.",
            );
        }

        if ($node->exists && $node->platform === 'macos' && $data->platformProvided && $data->platform !== 'macos') {
            throw new ResourceOperationException(
                errorCode: 'node.platform_unsupported',
                message: "Node [{$node->name}] is macOS and cannot be provisioned as {$data->platform}.",
            );
        }

        if ($data->roles !== [] || ($node->exists && $node->roles()->exists())) {
            throw new ResourceOperationException(
                errorCode: 'node.platform_unsupported',
                message: 'Node platform [macos] does not support service roles.',
            );
        }

        if ($node->exists && $node->instances()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'node.has_instances',
                message: "Node [{$node->name}] cannot be reprovisioned while it owns Instances.",
                status: 409,
            );
        }

        if (
            $data->settingsProvided
            || $this->overrideChanges($data->wireguardEndpointOverride, $node->exists ? $node->wireguard_endpoint_override : null)
            || $this->overrideChanges($data->dnsServerOverride, $node->exists ? $node->dns_server_override : null)
            || ($data->tldProvided && $data->tld !== ($node->exists ? $this->stringOrNull($node->tld) : null))
            || ($data->clusterProvided && $data->clusterId !== ($node->exists ? $node->cluster_id : null))
            || ($data->lanIpProvided && $data->lanIp !== ($node->exists ? $this->stringOrNull($node->getAttribute('lan_ip')) : null))
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.platform_unsupported',
                message: 'macOS enrollment does not change Cluster, DNS, tunnel, or storage settings.',
            );
        }
    }

    /** @return array{0: string, 1: int} */
    private function sshTarget(Node $node, ProvisionNodeData $data): array
    {
        $host = $data->publicSshHost !== ''
            ? $data->publicSshHost
            : ($node->exists ? $node->public_ssh_host : '');
        $port = $node->exists ? $node->public_ssh_port : $data->publicSshPort;

        if ($host === '') {
            throw new ResourceOperationException(
                errorCode: 'node.ssh_host_fingerprint_required',
                message: "An expected SSH host fingerprint is required for node [{$node->name}].",
            );
        }

        return [$host, $port];
    }

    private function architecture(Node $node, ProvisionNodeData $data, MacOsEnrollmentObservation $observation): string
    {
        $recorded = $this->pinned($node) ? $this->stringOrNull($node->architecture) : null;
        $recorded = $recorded === '' ? null : $recorded;
        $expected = $data->architecture ?? $recorded;

        if ($expected !== null && $expected !== $observation->architecture) {
            throw new NodeArchitectureMismatchException($node->name, $expected, $observation->architecture);
        }

        return $recorded ?? $observation->architecture;
    }

    private function persist(
        Node $node,
        string $host,
        int $port,
        string $account,
        string $address,
        string $architecture,
        HostKey $hostKey,
    ): void {
        $node->fill([
            'status' => LifecycleStatus::Active,
            'platform' => 'macos',
            'architecture' => $architecture,
            'user' => $account,
            'public_ssh_host' => $host,
            'public_ssh_port' => $port,
            'wireguard_ip' => $address,
            'ssh_host_key_type' => $hostKey->type,
            'ssh_host_key' => $hostKey->value,
            'ssh_host_fingerprint' => $hostKey->fingerprint,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $node->save();
    }

    private function pin(string $host, int $port, string $address, HostKey $hostKey): void
    {
        $this->knownHosts->put($host, $port, $hostKey);

        if ($address !== $host || $port !== 22) {
            $this->knownHosts->put($address, 22, $hostKey);
        }
    }

    private function recordPinFailure(
        Node $node,
        bool $existed,
        string $host,
        int $port,
        string $account,
        string $address,
        string $architecture,
        NodeProvisioningException $failure,
    ): void {
        if ($existed) {
            return;
        }

        $node->fill([
            'status' => LifecycleStatus::Failed,
            'platform' => 'macos',
            'architecture' => $architecture,
            'user' => $account,
            'public_ssh_host' => $host,
            'public_ssh_port' => $port,
            'wireguard_ip' => $address,
            'ssh_host_key_type' => null,
            'ssh_host_key' => null,
            'ssh_host_fingerprint' => null,
            'failed_step' => $failure->step,
            'error_code' => $failure->errorCode,
        ]);
        $node->save();
    }

    private function recordRemoteFailure(
        Node $node,
        bool $existed,
        string $host,
        int $port,
        string $account,
        string $address,
        NodeProvisioningException $failure,
    ): void {
        if ($existed) {
            return;
        }

        $node->fill([
            'status' => LifecycleStatus::Failed,
            'platform' => 'macos',
            'architecture' => null,
            'user' => $account,
            'public_ssh_host' => $host,
            'public_ssh_port' => $port,
            'wireguard_ip' => $address,
            'ssh_host_key_type' => null,
            'ssh_host_key' => null,
            'ssh_host_fingerprint' => null,
            'failed_step' => $failure->step,
            'error_code' => $failure->errorCode,
        ]);
        $node->save();
    }

    private function httpFailure(NodeProvisioningException $exception): Throwable
    {
        if (! in_array($exception->errorCode, [
            'node.platform_mismatch',
            'node.architecture_mismatch',
            'node.wireguard_required',
        ], true)) {
            return $exception;
        }

        return new ResourceOperationException(
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
            status: 409,
            previous: $exception,
            details: ['step' => $exception->step],
        );
    }

    private function pinned(Node $node): bool
    {
        return $this->pinnedFingerprint($node) !== null;
    }

    private function pinnedFingerprint(Node $node): ?string
    {
        if (! $node->exists) {
            return null;
        }

        $fingerprint = $this->stringOrNull($node->ssh_host_fingerprint);

        return $fingerprint === '' ? null : $fingerprint;
    }

    private function overrideChanges(?string $requested, mixed $stored): bool
    {
        return $requested !== null && $requested !== $this->stringOrNull($stored);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
