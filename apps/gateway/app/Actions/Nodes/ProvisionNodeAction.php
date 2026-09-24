<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Routes\ConvergeRouteAction;
use App\Data\Nodes\NodeData;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeArchitectureMismatchException;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeTld;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
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
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ProvisionNodeAction
{
    public function __construct(
        private AddNodeRoleAction $roles,
        private NodeConverger $converger,
        private ToolManagerMaterializer $toolManagers,
        private WireGuardAddressAllocator $addresses,
        private GatewayPeerProjectionManager $gatewayPeers,
        private NodeProvisioningLock $provisioningLock,
        private AppDevTldConverger $appDevTldConverger,
        private MetricsFleetReconciler $metrics,
        private NodeAgentRuntime $agent,
        private ManagedNodeEligibility $managedNodeEligibility,
        private ConfiguredStoragePathValidator $storagePaths,
        private UpdateNodeSettingsAction $nodeSettings,
        private ManagedUserAccountResolver $accounts,
        private ActiveTldScopeGuard $tldScope,
        private ?RouteMutationReconciler $routes = null,
        private ?ConvergeRouteAction $convergeRoute = null,
        private ?RouterLanIngressReconciler $lanIngress = null,
        private ?ClusterRouterDnsSelectionReconciler $dnsSelection = null,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(ProvisionNodeData $data): Node
    {
        try {
            return $this->provisioningLock->run($data->name, fn (): Node => $this->provision($data));
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function announceCreated(Node $node): Node
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::NodeCreated,
            $node->id,
            NodeData::fromModel($node)->toArray(),
        );

        return $node;
    }

    private function announceUpdated(Node $node): Node
    {
        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::NodeUpdated,
            $node->id,
            NodeData::fromModel($node)->toArray(),
        );

        return $node;
    }

    private function provision(ProvisionNodeData $data): Node
    {
        if (
            $data->user !== null
            && ! LinuxUserName::isValid($data->user)
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
        $wasNew = ! $node->exists;

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
            && $node->roles()->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'node.user_change_unsupported',
                message: "Node [{$data->name}] cannot change managed user while it owns roles or instances.",
                status: 409,
            );
        }

        $bootstrapUser = $data->user ?? ($node->exists ? $node->user : 'root');

        if (! LinuxUserName::isValid($bootstrapUser)) {
            throw new ResourceOperationException(
                errorCode: 'node.invalid_linux_user',
                message: 'The node Linux user name is invalid.',
            );
        }

        $identity = new NodeProvisioningIdentity($bootstrapUser, $managedUser);
        $platform = $this->platform($node, $data);
        $architecture = $this->recordedArchitecture($node);
        $previousTld = $node->exists && is_string($node->tld) ? $node->tld : null;
        $previousClusterId = $node->exists ? $node->cluster_id : null;
        $tld = $this->tld($node, $data, $clusterId);
        $convergeChangedAppDevTld = $node->exists && $previousTld !== $tld && $this->hasActiveAppDevRole($node);

        if ($node->exists && $node->appInstances()->exists()) {
            if ($this->isTldOnlyChange($node, $data, $tld, $clusterId) && $this->hasActiveAppDevRole($node)) {
                return $this->announceUpdated(
                    $this->changeNodeTld($node, $tld, $previousTld, $clusterId, $previousClusterId),
                );
            }

            throw new ResourceOperationException(
                errorCode: 'node.has_app_instances',
                message: "Node [{$node->name}] cannot be reprovisioned while it owns AppInstances.",
                status: 409,
            );
        }

        if ($node->exists) {
            $this->convergeGeneratedPrivateDomains($node, $tld, $clusterId, $previousTld, $previousClusterId);
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
            DB::transaction(function () use ($node, $tld, $clusterId, $previousTld, $previousClusterId): void {
                $this->tldScope->assertNodeTldAvailable($node, $tld, $clusterId);
                if ($node->exists) {
                    $this->routeReconciler()->reconcile(
                        nodeOverrides: [$node->id => ['tld' => $tld, 'cluster_id' => $clusterId]],
                        baselineNodeOverrides: [
                            $node->id => ['tld' => $previousTld, 'cluster_id' => $previousClusterId],
                        ],
                    );
                }
                $node->save();
            });
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
                    function (NodeObservation $observation) use ($node, $managedUser, $data): void {
                        $this->recordArchitecture($node, $data, $observation);
                        $node->user = $managedUser;
                        $this->toolManagers->converge($node, ToolManagerName::Apt);
                        $this->convergeRoles($node, $data->roles);
                    },
                    $rolelessOperator,
                );
            } else {
                $observation = $this->converger->converge(
                    $node,
                    $identity,
                    $data->expectedSshHostFingerprint,
                    $rolelessOperator,
                );
                $this->recordArchitecture($node, $data, $observation);
                $node->user = $managedUser;
                $this->toolManagers->converge($node, ToolManagerName::Apt);
                $this->convergeRoles($node, $data->roles);
            }
        } catch (NodeProvisioningException $exception) {
            $this->handleFailure($node, $exception, $priorActiveState);

            throw $exception;
        } catch (NodeArchitectureMismatchException $exception) {
            $failure = new NodeProvisioningException(
                step: 'machine-architecture',
                errorCode: NodeArchitectureMismatchException::ERROR_CODE,
                message: $exception->getMessage(),
                previous: $exception,
            );
            $this->handleFailure($node, $failure, $priorActiveState);

            throw $exception->toRefusal();
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

        $lanClusterIds = array_values(array_unique(array_filter(
            [$clusterId, $previousClusterId],
            is_int(...),
        )));

        try {
            $this->expandRouterLanIngress($node, $lanClusterIds);
        } catch (Throwable $exception) {
            $this->failRouterLanIngress($node, $exception, $priorActiveState, $lanClusterIds);
        }

        try {
            $this->expandDnsSelection($node, $lanClusterIds);
        } catch (Throwable $exception) {
            $this->failDnsSelection($node, $exception, $priorActiveState, $lanClusterIds);
        }

        try {
            DB::transaction(function () use ($node, $managedUser): void {
                $node->update([
                    'user' => $managedUser,
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
            });
        } catch (Throwable $exception) {
            $this->bestEffortPruneNetwork($lanClusterIds);

            if ($priorActiveState !== null) {
                $this->restorePriorActiveState($node, $priorActiveState);
                $this->bestEffortExpandNetwork($lanClusterIds);
                $this->bestEffortPruneNetwork($lanClusterIds);
            }

            throw $exception;
        }

        $this->dnsSelection()->prune(clusterIds: $lanClusterIds);
        $this->lanIngress()->prune(clusterIds: $lanClusterIds);

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

        $node->refresh();

        if ($this->managedNodeEligibility->allows($node)) {
            try {
                $this->agent->converge($node);
            } catch (Throwable $exception) {
                $failure = new NodeProvisioningException(
                    step: 'agent',
                    errorCode: 'node.agent_install_failed',
                    message: 'Node agent installation failed.',
                    previous: $exception,
                );
                $this->handleFailure($node, $failure, $priorActiveState);

                throw $failure;
            }
        }

        $result = $node->refresh()->load('roles');

        return $wasNew ? $this->announceCreated($result) : $this->announceUpdated($result);
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
        $requested = $data->tldProvided || $data->tld !== null
            ? $data->tld
            : (is_string($node->tld) ? $node->tld : null);

        if ($requested === null) {
            if (! $this->hasAppDevRole($node, $data) || $this->activeClusterTld($clusterId) !== null) {
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

    private function activeClusterTld(?int $clusterId): ?string
    {
        if ($clusterId === null) {
            return null;
        }

        $cluster = Cluster::query()->find($clusterId);

        if (! $cluster instanceof Cluster) {
            return null;
        }

        return $cluster->state === ClusterState::Active && is_string($cluster->tld)
            ? $cluster->tld
            : null;
    }

    private function changeNodeTld(
        Node $node,
        ?string $tld,
        ?string $previousTld,
        ?int $clusterId,
        ?int $previousClusterId,
    ): Node {
        $this->tldScope->assertNodeTldAvailable($node, $tld, $clusterId);
        $this->convergeGeneratedPrivateDomains($node, $tld, $clusterId, $previousTld, $previousClusterId);

        DB::transaction(function () use ($node, $tld, $clusterId, $previousTld, $previousClusterId): void {
            $this->tldScope->assertNodeTldAvailable($node, $tld, $clusterId);
            $this->routeReconciler()->reconcile(
                nodeOverrides: [$node->id => ['tld' => $tld, 'cluster_id' => $clusterId]],
                baselineNodeOverrides: [
                    $node->id => ['tld' => $previousTld, 'cluster_id' => $previousClusterId],
                ],
            );
            $node->update(['tld' => $tld]);
        });

        if ($previousTld !== $tld && $this->hasActiveAppDevRole($node)) {
            $this->convergeChangedAppDevTld($node->refresh(), $previousTld);
        }

        return $node->refresh()->load('roles');
    }

    private function convergeGeneratedPrivateDomains(
        Node $node,
        ?string $tld,
        ?int $clusterId,
        ?string $previousTld,
        ?int $previousClusterId,
    ): void {
        foreach ($this->routeReconciler()->generatedPrivateDomainChanges(
            nodeOverrides: [$node->id => ['tld' => $tld, 'cluster_id' => $clusterId]],
            baselineNodeOverrides: [
                $node->id => ['tld' => $previousTld, 'cluster_id' => $previousClusterId],
            ],
        ) as $change) {
            $this->convergeRoute()->execute($change['route'], $change['domain'], allowGenerated: true);
        }
    }

    private function isTldOnlyChange(Node $node, ProvisionNodeData $data, ?string $tld, ?int $clusterId): bool
    {
        if ($tld === $node->tld) {
            return false;
        }

        if ($clusterId !== $node->cluster_id) {
            return false;
        }

        if ($data->roles !== []) {
            return false;
        }

        if ($data->user !== null && $data->user !== $node->user) {
            return false;
        }

        if ($data->orbitUser !== null && $data->orbitUser !== $node->user) {
            return false;
        }

        if ($data->publicSshHost !== '' && $data->publicSshHost !== $node->public_ssh_host) {
            return false;
        }

        if ($data->wireguardIp !== null && $data->wireguardIp !== $node->wireguard_ip) {
            return false;
        }

        if ($data->lanIpProvided && $data->lanIp !== $node->getAttribute('lan_ip')) {
            return false;
        }

        if ($data->settingsProvided || $data->clusterProvided) {
            return false;
        }

        if ($data->wireguardEndpointOverride !== null || $data->dnsServerOverride !== null) {
            return false;
        }

        return $data->architecture === null || $data->architecture === $node->architecture;
    }

    private function generatedDomainsMovedForward(Node $node, ?string $previousTld): bool
    {
        $routes = Route::query()
            ->where('provenance', RouteProvenance::Generated)
            ->where('publication', RoutePublication::Private)
            ->whereIn('status', [RouteStatus::Active->value, RouteStatus::Activating->value])
            ->where(function ($query) use ($node): void {
                $query
                    ->where('generation_basis_node_id', $node->id)
                    ->orWhere('node_id', $node->id)
                    ->orWhereHas(
                        'targets.appInstance',
                        static fn ($target) => $target->where('node_id', $node->id),
                    );
            })
            ->get();

        foreach ($routes as $route) {
            if ($previousTld === null || ! str_ends_with($route->domain, ".{$previousTld}")) {
                return true;
            }
        }

        return false;
    }

    private function routeReconciler(): RouteMutationReconciler
    {
        return $this->routes ?? app(RouteMutationReconciler::class);
    }

    private function convergeRoute(): ConvergeRouteAction
    {
        return $this->convergeRoute ?? app(ConvergeRouteAction::class);
    }

    private function lanIngress(): RouterLanIngressReconciler
    {
        return $this->lanIngress ?? app(RouterLanIngressReconciler::class);
    }

    private function dnsSelection(): ClusterRouterDnsSelectionReconciler
    {
        return $this->dnsSelection ?? app(ClusterRouterDnsSelectionReconciler::class);
    }

    /** @param list<int> $clusterIds */
    private function expandRouterLanIngress(Node $node, array $clusterIds): void
    {
        $this->lanIngress()->expand(
            nodeOverrides: [
                $node->id => [
                    'status' => LifecycleStatus::Active,
                    'cluster_id' => $node->cluster_id,
                    'lan_ip' => is_string($node->lan_ip) ? $node->lan_ip : null,
                    'wireguard_ip' => is_string($node->wireguard_ip) ? $node->wireguard_ip : null,
                    'wireguard_public_key' => is_string($node->wireguard_public_key)
                        ? $node->wireguard_public_key
                        : null,
                ],
            ],
            clusterIds: $clusterIds,
        );
    }

    /** @param list<int> $clusterIds */
    private function expandDnsSelection(Node $node, array $clusterIds): void
    {
        $this->dnsSelection()->expand(
            nodeOverrides: [
                $node->id => [
                    'status' => LifecycleStatus::Active,
                    'cluster_id' => $node->cluster_id,
                    'lan_ip' => is_string($node->lan_ip) ? $node->lan_ip : null,
                    'wireguard_ip' => is_string($node->wireguard_ip) ? $node->wireguard_ip : null,
                    'wireguard_public_key' => is_string($node->wireguard_public_key)
                        ? $node->wireguard_public_key
                        : null,
                ],
            ],
            clusterIds: $clusterIds,
        );
    }

    private function syncRouterLanIngress(Node $node): void
    {
        $clusterId = $node->cluster_id;

        if (! is_int($clusterId)) {
            return;
        }

        $this->bestEffortExpandNetwork([$clusterId]);
        $this->bestEffortPruneNetwork([$clusterId]);
    }

    /** @param list<int> $clusterIds */
    private function bestEffortExpandNetwork(array $clusterIds): void
    {
        try {
            $this->lanIngress()->expand(clusterIds: $clusterIds);
        } catch (Throwable) {
        }

        try {
            $this->dnsSelection()->expand(clusterIds: $clusterIds);
        } catch (Throwable) {
        }
    }

    /** @param list<int> $clusterIds */
    private function bestEffortPruneNetwork(array $clusterIds): void
    {
        try {
            $this->dnsSelection()->prune(clusterIds: $clusterIds);
        } catch (Throwable) {
        }

        try {
            $this->lanIngress()->prune(clusterIds: $clusterIds);
        } catch (Throwable) {
        }
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  ?array<string, mixed>  $priorActiveState
     */
    private function failRouterLanIngress(
        Node $node,
        Throwable $exception,
        ?array $priorActiveState,
        array $clusterIds,
    ): never {
        $this->bestEffortPruneNetwork($clusterIds);

        $failure = new NodeProvisioningException(
            step: 'router-lan-ingress',
            errorCode: $exception instanceof FirewallOperationException
                ? $exception->errorCode
                : 'router.lan_ingress_failed',
            message: "Could not reconcile Router LAN ingress for node [{$node->name}].",
            previous: $exception,
            result: $exception instanceof FirewallOperationException ? $exception->result : null,
        );
        $this->handleFailure($node, $failure, $priorActiveState);

        throw $failure;
    }

    /**
     * @param  list<int>  $clusterIds
     * @param  ?array<string, mixed>  $priorActiveState
     */
    private function failDnsSelection(
        Node $node,
        Throwable $exception,
        ?array $priorActiveState,
        array $clusterIds,
    ): never {
        $this->bestEffortPruneNetwork($clusterIds);

        $failure = new NodeProvisioningException(
            step: 'private-dns',
            errorCode: $exception instanceof RuntimeConvergenceException
                ? $exception->errorCode
                : 'app-dev.dns_config_failed',
            message: "Could not reconcile Cluster Router DNS selection for node [{$node->name}].",
            previous: $exception,
            result: $exception instanceof RuntimeConvergenceException ? $exception->result : null,
        );
        $this->handleFailure($node, $failure, $priorActiveState);

        throw $failure;
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

    private function recordedArchitecture(Node $node): ?string
    {
        return $node->exists && is_string($node->architecture) && $node->architecture !== ''
            ? $node->architecture
            : null;
    }

    /**
     * Record the architecture the bootstrap observed on a Node that has none.
     *
     * An existing record wins over the request and the observation. A request
     * value for a Node without a record must equal the observation.
     */
    private function recordArchitecture(Node $node, ProvisionNodeData $data, NodeObservation $observation): void
    {
        if ($this->recordedArchitecture($node) !== null) {
            return;
        }

        if ($data->architecture !== null && $data->architecture !== $observation->architecture) {
            throw new NodeArchitectureMismatchException($node->name, $data->architecture, $observation->architecture);
        }

        $node->update(['architecture' => $observation->architecture]);
    }

    private function hasAppDevRole(Node $node, ProvisionNodeData $data): bool
    {
        return
            in_array(needle: RoleName::AppDev, haystack: $data->roles, strict: true)
            || $node->exists && $node->roles()->where('role', RoleName::AppDev->value)->exists();
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
            if ($this->generatedDomainsMovedForward($node, $previousTld)) {
                throw $exception;
            }

            $currentTld = is_string($node->tld) ? $node->tld : null;
            DB::transaction(function () use ($node, $previousTld, $currentTld): void {
                $this->routeReconciler()->reconcile(
                    nodeOverrides: [$node->id => ['tld' => $previousTld, 'cluster_id' => $node->cluster_id]],
                    baselineNodeOverrides: [$node->id => ['tld' => $currentTld, 'cluster_id' => $node->cluster_id]],
                );
                $node->update(['tld' => $previousTld]);
            });

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
            $this->restorePriorActiveState($node, $priorActiveState);
            $this->syncRouterLanIngress($node);
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

    /** @param array<string, mixed> $priorActiveState */
    private function restorePriorActiveState(Node $node, array $priorActiveState): void
    {
        $currentTld = is_string($node->tld) ? $node->tld : null;
        $currentClusterId = $node->cluster_id;
        $previousTld = is_string($priorActiveState['tld'] ?? null) ? $priorActiveState['tld'] : null;
        $previousClusterId = is_int($priorActiveState['cluster_id'] ?? null)
            ? $priorActiveState['cluster_id']
            : null;
        $keepTld = $this->generatedDomainsMovedForward($node, $previousTld);

        DB::transaction(function () use (
            $node,
            $priorActiveState,
            $currentTld,
            $currentClusterId,
            $previousTld,
            $previousClusterId,
            $keepTld,
        ): void {
            if (! $keepTld) {
                $this->routeReconciler()->reconcile(
                    nodeOverrides: [
                        $node->id => ['tld' => $previousTld, 'cluster_id' => $previousClusterId],
                    ],
                    baselineNodeOverrides: [
                        $node->id => ['tld' => $currentTld, 'cluster_id' => $currentClusterId],
                    ],
                );
            }

            $state = $priorActiveState;

            if ($keepTld) {
                $state['tld'] = $currentTld;
            }

            $node->update($state);
        });
    }
}
