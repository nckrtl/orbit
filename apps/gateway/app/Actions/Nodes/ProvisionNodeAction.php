<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeTld;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Models\Node;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity Node provisioning keeps its ordered identity, role, and recovery gates together. */
final readonly class ProvisionNodeAction
{
    public function __construct(
        private AddNodeRoleAction $roles,
        private NodeConverger $converger,
        private ToolManagerMaterializer $toolManagers,
        private WireGuardAddressAllocator $addresses,
        private GatewayPeerProjectionManager $gatewayPeers,
    ) {}

    /** @mago-expect lint:halstead Ordered provisioning keeps persisted state and failure recovery in one transaction-like flow. */
    public function execute(ProvisionNodeData $data): Node
    {
        $this->validateEndpointOverride($data);
        $node = Node::query()->firstOrNew(['name' => $data->name]);
        $platform = $this->platform($node, $data);
        $architecture = $this->architecture($node, $data);
        $tld = $this->tld($node, $data);

        if (
            $platform === 'linux'
            && $node->ssh_host_fingerprint === null
            && $data->expectedSshHostFingerprint === null
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.ssh_host_fingerprint_required',
                message: "An expected SSH host fingerprint is required for node [{$data->name}].",
            );
        }

        $requestedAddress =
            $data->wireguardAddress ?? (is_string($node->wireguard_address) ? $node->wireguard_address : null);
        $wireguardAddress = $this->addresses->forProvisioning($requestedAddress, $node);
        $publicSshHost = $data->publicSshHost;
        /** @var ?string $failedStep */
        $failedStep = $node->getAttribute('failed_step');
        /** @var ?string $errorCode */
        $errorCode = $node->getAttribute('error_code');
        /** @var ?string $sshHostKeyType */
        $sshHostKeyType = $node->getAttribute('ssh_host_key_type');
        /** @var ?string $sshHostKey */
        $sshHostKey = $node->getAttribute('ssh_host_key');
        /** @var ?array<string, mixed> $priorActiveState */
        $priorActiveState = $node->exists && $node->status === LifecycleStatus::Active
            ? [
                'status' => $node->status,
                'platform' => $node->platform,
                'architecture' => $node->architecture,
                'tld' => $node->tld,
                'public_ssh_host' => $node->public_ssh_host,
                'public_ssh_port' => $node->public_ssh_port,
                'user' => $node->user,
                'wireguard_address' => $node->wireguard_address,
                'wireguard_endpoint_override' => $node->wireguard_endpoint_override,
                'dns_server_override' => $node->dns_server_override,
                'failed_step' => $failedStep,
                'error_code' => $errorCode,
                'ssh_host_key_type' => $sshHostKeyType,
                'ssh_host_key' => $sshHostKey,
                'ssh_host_fingerprint' => $node->ssh_host_fingerprint,
                'wireguard_public_key' => $node->wireguard_public_key,
            ]
            : null;

        if ($publicSshHost === '' && $node->exists && $node->public_ssh_host !== '') {
            $publicSshHost = $node->public_ssh_host;
        }

        if ($publicSshHost === '') {
            $publicSshHost = $wireguardAddress;
        }

        foreach ($data->roles as $role) {
            $this->roles->preflightDuringProvisioning($node, $role, $data->roles);
        }

        $node->fill([
            'status' => LifecycleStatus::Provisioning,
            'platform' => $platform,
            'architecture' => $architecture,
            'tld' => $tld,
            'public_ssh_host' => $publicSshHost,
            'public_ssh_port' => $node->exists ? $node->public_ssh_port : $data->publicSshPort,
            'user' => $node->exists ? $node->user : 'orbit',
            'wireguard_address' => $wireguardAddress,
            'wireguard_endpoint_override' => $data->wireguardEndpointOverride ?? $node->wireguard_endpoint_override,
            'dns_server_override' => $data->dnsServerOverride ?? $node->dns_server_override,
            'failed_step' => null,
            'error_code' => null,
        ])->save();

        try {
            if ($priorActiveState !== null && $this->converger instanceof RecoverableNodeConverger) {
                $this->converger->convergeRecoverably(
                    $node,
                    $data->expectedSshHostFingerprint,
                    function () use ($node): void {
                        $this->toolManagers->converge($node, ToolManagerName::Apt);
                    },
                );
            } else {
                $this->converger->converge($node, $data->expectedSshHostFingerprint);
                $this->toolManagers->converge($node, ToolManagerName::Apt);
            }
        } catch (NodeProvisioningException $exception) {
            $this->handleFailure($node, $exception, $priorActiveState);

            throw $exception;
        } catch (SshHostKeyScanException $exception) {
            $failure = new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$node->name}].",
                previous: $exception,
                result: $exception->result,
            );
            $this->handleFailure($node, $failure, $priorActiveState);

            throw $failure;
        } catch (Throwable $exception) {
            $failure = new NodeProvisioningException(
                step: 'unknown',
                errorCode: 'node.provision_failed',
                message: 'Node provisioning failed.',
                previous: $exception,
            );
            $this->handleFailure($node, $failure, $priorActiveState);

            throw $failure;
        }

        $node->update([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);

        foreach ($data->roles as $role) {
            $this->roles->executeDuringProvisioning($node, $role);
        }

        return $node->refresh()->load('roles');
    }

    private function tld(Node $node, ProvisionNodeData $data): ?string
    {
        $requested = $data->tld ?? (is_string($node->tld) ? $node->tld : null);

        if ($requested === null) {
            if (! $this->hasAppDevRole($node, $data)) {
                return null;
            }

            throw new ResourceOperationException(
                errorCode: 'node.tld_required',
                message: "An app-dev TLD is required for node [{$data->name}].",
            );
        }

        $tld = NodeTld::normalize($requested);

        if (! NodeTld::isValid($tld)) {
            throw new ResourceOperationException(
                errorCode: 'node.tld_invalid',
                message: "Node TLD [{$requested}] is invalid.",
            );
        }

        if (
            $node->exists
            && is_string($node->tld)
            && $node->tld !== $tld
            && $node->instances()->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.tld_change_unsupported',
                message: "Node [{$data->name}] cannot change TLD while it owns instances.",
                status: 409,
            );
        }

        $taken = Node::query()
            ->where('tld', $tld)
            ->when($node->exists, static fn ($query) => $query->whereKeyNot($node->id))
            ->exists();

        if ($taken) {
            throw new ResourceOperationException(
                errorCode: 'node.tld_taken',
                message: "Node TLD [{$tld}] is already assigned.",
                status: 409,
            );
        }

        return $tld;
    }

    private function platform(Node $node, ProvisionNodeData $data): string
    {
        $platform = $node->exists && $node->platform !== ''
            ? $node->platform
            : $data->platform;

        if ($platform !== 'linux') {
            throw new ResourceOperationException(
                errorCode: 'node.platform_unsupported',
                message: "Node platform [{$platform}] is not supported.",
            );
        }

        return $platform;
    }

    private function architecture(Node $node, ProvisionNodeData $data): string
    {
        $architecture =
            $node->exists && is_string($node->architecture) && $node->architecture !== ''
                ? $node->architecture
                : $data->architecture;

        if ($architecture === null) {
            throw new ResourceOperationException(
                errorCode: 'node.architecture_required',
                message: "The real architecture is required for node [{$data->name}].",
            );
        }

        return $architecture;
    }

    private function hasAppDevRole(Node $node, ProvisionNodeData $data): bool
    {
        return (
            in_array(needle: RoleName::AppDev, haystack: $data->roles, strict: true)
            || $node->exists && $node->roles()->where('role', RoleName::AppDev->value)->exists()
        );
    }

    private function validateEndpointOverride(ProvisionNodeData $data): void
    {
        if (
            $data->wireguardEndpointOverride !== null
            && ! WireGuardEndpoint::isValid($data->wireguardEndpointOverride)
        ) {
            throw new ResourceOperationException(
                errorCode: 'vpn.endpoint_override_invalid',
                message: "WireGuard endpoint override [{$data->wireguardEndpointOverride}] is invalid.",
            );
        }
    }

    private function markFailed(Node $node, NodeProvisioningException $exception): void
    {
        $failure = [
            'status' => LifecycleStatus::Failed,
            'failed_step' => $exception->step,
            'error_code' => $exception->errorCode,
        ];

        $node->update($failure);
    }

    /** @param ?array<string, mixed> $priorActiveState */
    private function handleFailure(
        Node $node,
        NodeProvisioningException $failure,
        ?array $priorActiveState,
    ): void {
        if ($priorActiveState === null) {
            $this->markFailed($node, $failure);

            return;
        }

        try {
            $node->update($priorActiveState);
        } catch (Throwable) {
            throw new NodeProvisioningException(
                step: 'node-rollback',
                errorCode: 'node.reprovision_rollback_failed',
                message: 'Node reprovisioning rollback failed.',
            );
        }

        try {
            $this->gatewayPeers->restore($node->refresh());
        } catch (Throwable) {
            throw new NodeProvisioningException(
                step: 'node-rollback',
                errorCode: 'node.reprovision_rollback_failed',
                message: 'Node reprovisioning rollback failed.',
            );
        }

        if ($failure->errorCode === 'vpn.peer_rollback_failed') {
            throw new NodeProvisioningException(
                step: 'node-rollback',
                errorCode: 'node.reprovision_rollback_failed',
                message: 'Node reprovisioning rollback failed.',
            );
        }
    }
}
