<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\ProvisionNodeData;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeTld;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods Node provisioning keeps its ordered identity, role, and recovery gates together. */
final readonly class ProvisionNodeAction
{
    /** @mago-expect lint:excessive-parameter-list The action coordinates its complete provisioning boundary. */
    public function __construct(
        private AddNodeRoleAction $roles,
        private NodeConverger $converger,
        private ToolManagerMaterializer $toolManagers,
        private WireGuardAddressAllocator $addresses,
        private GatewayPeerProjectionManager $gatewayPeers,
        private NodeProvisioningLock $provisioningLock,
        private AppDevTldConverger $appDevTldConverger,
        private MetricsFleetReconciler $metrics,
        private ConfiguredStoragePathValidator $storagePaths,
        private UpdateNodeSettingsAction $nodeSettings,
        private ManagedUserAccountResolver $accounts,
        private ActiveTldScopeGuard $tldScope,
    ) {}

    public function execute(ProvisionNodeData $data): Node
    {
        try {
            return $this->provisioningLock->run($data->name, fn (): Node => $this->provision($data));
        } catch (NodeProvisioningLockException) {
            throw new ResourceOperationException(
                errorCode: 'node.provisioning_busy',
                message: "Node [{$data->name}] is already being provisioned.",
                status: 409,
            );
        }
    }

    /** @mago-expect lint:halstead Ordered provisioning keeps persisted state and failure recovery in one transaction-like flow. */
    private function provision(ProvisionNodeData $data): Node
    {
        if (
            ! LinuxUserName::isValid($data->user)
            || $data->orbitUser !== null
            && ! LinuxUserName::isValid($data->orbitUser)
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.invalid_linux_user',
                message: 'The node Linux user name is invalid.',
            );
        }
        $this->validateEndpointOverride($data);

        if ($data->settingsProvided) {
            $this->storagePaths->validateGrammar($data->settings);
        }

        $node = Node::query()->firstOrNew(['name' => $data->name]);
        $clusterId = $data->clusterId ?? ($node->exists ? $node->cluster_id : null);
        $lanIp = $data->lanIpProvided
            ? $data->lanIp
            : (is_string($node->getAttribute('lan_ip')) ? $node->getAttribute('lan_ip') : null);

        if (
            $data->clusterId !== null
            && $node->exists
            && $node->cluster_id !== null
            && $node->cluster_id !== $data->clusterId
        ) {
            throw new ResourceOperationException(
                errorCode: 'cluster.membership_conflict',
                message: "Node [{$node->name}] already belongs to another Cluster.",
                status: 409,
            );
        }

        if ($clusterId !== null && ! Cluster::query()->whereKey($clusterId)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'cluster.not_found',
                message: 'The selected Cluster does not exist.',
                status: 404,
            );
        }

        if (
            $clusterId !== null
            && $lanIp !== null
            && Node::query()
                ->where('cluster_id', $clusterId)
                ->where('lan_ip', $lanIp)
                ->when($node->exists, static fn ($query) => $query->whereKeyNot($node->id))
                ->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'cluster.lan_ip_conflict',
                message: "LAN IP [{$lanIp}] is already assigned in the Cluster.",
                status: 409,
            );
        }

        $managedUser = $data->orbitUser ?? ($node->exists ? $node->user : 'orbit');

        if ($data->settingsProvided && $node->exists) {
            $this->storagePaths->validateEffective(
                $data->settings,
                $node,
                $this->accounts->resolve($node),
            );
        }

        if (! LinuxUserName::isValid($managedUser)) {
            throw new ResourceOperationException(
                errorCode: 'node.invalid_linux_user',
                message: 'The node Linux user name is invalid.',
            );
        }

        if (
            $node->exists
            && $node->user !== $managedUser
            && ($node->roles()->exists()
            || $node->instances()->exists())
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.user_change_unsupported',
                message: "Node [{$data->name}] cannot change managed user while it owns roles or instances.",
                status: 409,
            );
        }

        $identity = new NodeProvisioningIdentity($data->user, $managedUser);
        $platform = $this->platform($node, $data);
        $architecture = $this->architecture($node, $data);
        $previousTld = $node->exists && is_string($node->tld) ? $node->tld : null;
        $tld = $this->tld($node, $data, $clusterId);
        $convergeChangedAppDevTld = $node->exists && $previousTld !== $tld && $this->hasActiveAppDevRole($node);

        if (
            $node->exists
            && $previousTld !== $tld
            && $this->hasAppDevRole($node, $data)
            && ! $convergeChangedAppDevTld
            && $node->instances()->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.tld_change_unsupported',
                message: "Node [{$data->name}] cannot change TLD while app-dev is not active.",
                status: 409,
            );
        }

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

        $requestedAddress = $data->wireguardIp ?? (is_string($node->wireguard_ip) ? $node->wireguard_ip : null);
        $wireguardIp = $this->addresses->forProvisioning($requestedAddress, $node);
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
                'cluster_id' => $node->cluster_id,
                'platform' => $node->platform,
                'architecture' => $node->architecture,
                'tld' => $node->tld,
                'public_ssh_host' => $node->public_ssh_host,
                'public_ssh_port' => $node->public_ssh_port,
                'user' => $node->user,
                'wireguard_ip' => $node->wireguard_ip,
                'lan_ip' => $node->getAttribute('lan_ip'),
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
            $publicSshHost = $wireguardIp;
        }

        foreach ($data->roles as $role) {
            $this->roles->preflightDuringProvisioning($node, $role, $data->roles);
        }
        $rolelessOperator = $data->roles === [] && (! $node->exists || $node->roles()->doesntExist());

        $node->fill([
            'status' => LifecycleStatus::Provisioning,
            'cluster_id' => $clusterId,
            'platform' => $platform,
            'architecture' => $architecture,
            'tld' => $tld,
            'user' => $priorActiveState !== null ? $node->user : $managedUser,
            'public_ssh_host' => $publicSshHost,
            'public_ssh_port' => $node->exists ? $node->public_ssh_port : $data->publicSshPort,
            'wireguard_ip' => $wireguardIp,
            'lan_ip' => $lanIp,
            'wireguard_endpoint_override' => $data->wireguardEndpointOverride ?? $node->wireguard_endpoint_override,
            'dns_server_override' => $data->dnsServerOverride ?? $node->dns_server_override,
            'failed_step' => null,
            'error_code' => null,
        ]);

        try {
            $node->save();
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                errorCode: 'cluster.lan_ip_conflict',
                message: 'The Node network identity conflicts with existing Cluster state.',
                status: 409,
                previous: $exception,
            );
        }

        try {
            if ($priorActiveState !== null && $this->converger instanceof RecoverableNodeConverger) {
                $this->converger->convergeRecoverably(
                    $node,
                    $identity,
                    $data->expectedSshHostFingerprint,
                    function () use ($node, $managedUser, $data): void {
                        $node->user = $managedUser;
                        $this->toolManagers->converge($node, ToolManagerName::Apt);
                        $this->convergeRoles($node, $data->roles);
                    },
                    $rolelessOperator,
                );
            } else {
                $this->converger->converge(
                    $node,
                    $identity,
                    $data->expectedSshHostFingerprint,
                    $rolelessOperator,
                );
                $node->user = $managedUser;
                $this->toolManagers->converge($node, ToolManagerName::Apt);
                $this->convergeRoles($node, $data->roles);
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

        if ($convergeChangedAppDevTld) {
            $this->convergeChangedAppDevTld($node, $previousTld);
        }

        $node->update([
            'user' => $managedUser,
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);

        if ($data->settingsProvided) {
            try {
                $this->nodeSettings->persistDuringProvisioning($node->refresh(), $data->settings);
            } catch (ResourceOperationException $exception) {
                throw $exception;
            } catch (RuntimeConvergenceException $exception) {
                throw new ResourceOperationException(
                    errorCode: $exception->errorCode,
                    message: $exception->getMessage(),
                    previous: $exception,
                );
            } catch (Throwable $exception) {
                $failure = new NodeProvisioningException(
                    step: 'node-storage-root',
                    errorCode: 'node.settings_root_failed',
                    message: 'Node storage settings could not be prepared.',
                    previous: $exception,
                );
                $this->markFailed($node, $failure);

                throw $failure;
            }
        }

        // Role convergence runs while the node is still provisioning, and
        // exporter selection only ever considers active nodes. The node is
        // active here, so every provisioning outcome reconciles: a role-bearing
        // node becomes a selected exporter, and a roleless node picks up an
        // explicit preference it kept from an earlier registration.
        try {
            $this->metrics->reconcile();
        } catch (Throwable $exception) {
            $failure = new NodeProvisioningException(
                step: 'metrics-exporters',
                errorCode: 'node.metrics_reconcile_failed',
                message: 'Metrics fleet reconciliation failed.',
                previous: $exception,
            );
            $this->markFailed($node, $failure);

            throw $failure;
        }

        return $node->refresh()->load('roles');
    }

    /** @param list<RoleName> $roles */
    private function convergeRoles(Node $node, array $roles): void
    {
        try {
            foreach ($roles as $role) {
                $this->roles->executeDuringProvisioning($node, $role);
            }
        } catch (NodeRoleOperationException $exception) {
            throw new NodeProvisioningException(
                step: "role:{$exception->step}",
                errorCode: 'node.role_convergence_failed',
                message: 'Node role provisioning failed.',
                previous: $exception,
                result: $exception->result,
            );
        }
    }

    private function tld(Node $node, ProvisionNodeData $data, ?int $clusterId): ?string
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

        $this->tldScope->assertNodeTldAvailable($node, $tld, $clusterId);

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

    private function hasActiveAppDevRole(Node $node): bool
    {
        return $node
            ->roles()
            ->where('role', RoleName::AppDev->value)
            ->where('status', LifecycleStatus::Active)
            ->exists();
    }

    private function convergeChangedAppDevTld(Node $node, ?string $previousTld): void
    {
        try {
            $this->appDevTldConverger->converge($node);
        } catch (Throwable $exception) {
            $node->update(['tld' => $previousTld]);

            try {
                $this->appDevTldConverger->converge($node->refresh());
                $node->update(['status' => LifecycleStatus::Active]);
            } catch (Throwable $rollbackException) {
                $node->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'app-dev-tld-rollback',
                    'error_code' => 'app-dev.tld_rollback_failed',
                ]);
                throw new RuntimeConvergenceException(
                    step: 'app-dev-tld-rollback',
                    errorCode: 'app-dev.tld_rollback_failed',
                    message: "Could not restore node [{$node->name}] TLD projections.",
                    previous: $rollbackException,
                    result: $rollbackException instanceof RuntimeConvergenceException
                        ? $rollbackException->result
                        : null,
                );
            }

            throw $exception;
        }
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
