<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Actions\Nodes\AssignRoleAction;
use App\Console\GatewayBoostInstallCommand;
use App\Domain\Activity\ActivityBroadcastObserver;
use App\Domain\AgentView\AgentProcessView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\Analytics\PlausibleRuntimeLifecycle;
use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\AppProd\AppProdCaddyManager;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Doctor\CaddyBuildInspector;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Gateway\GatewayCacheStore;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\Logs\InstanceLogReader;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionInstanceProvisioner;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\ProductionInstanceContentRetention;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsPublicationManager as MetricsPublicationManagerContract;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessUsageIndex;
use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Projects\TiaBaselineSource;
use App\Domain\ProxyCli\ProxyCliAccountControlClient;
use App\Domain\ProxyCli\ProxyCliCache;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Http\Streaming\DeploymentStreamConnection;
use App\Http\Streaming\NativeDeploymentStreamConnection;
use App\Infrastructure\Activity\ActivityPropertiesObserver;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\NativeAgentViewConverger;
use App\Infrastructure\AgentView\ProcessAgentViewPublisher;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Analytics\NativeAnalyticsClickhouseConfigurationManager;
use App\Infrastructure\Analytics\NativeAnalyticsPublicationManager;
use App\Infrastructure\Analytics\NativeAnalyticsRoleSettingsRepository;
use App\Infrastructure\Analytics\NativeAnalyticsSecretManager;
use App\Infrastructure\Analytics\NativeAnalyticsStatsKeyStore;
use App\Infrastructure\Analytics\NativeAnalyticsTrackingRouteProjector;
use App\Infrastructure\Analytics\NativePlausibleRuntimeLifecycle;
use App\Infrastructure\Analytics\PlausibleCommunityEditionStatsDriver;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\AppDev\NativeAppDevTldConverger;
use App\Infrastructure\AppDev\NativeClusterRouterDnsSelectionReconciler;
use App\Infrastructure\AppDev\NativeDevelopmentProjectionOperationLock;
use App\Infrastructure\AppDev\RemoteAgentationSiteProjection;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\AppDev\RemoteVitePortRuntime;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Infrastructure\Broadcasting\ReverbBroadcaster;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ProxyCliCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateIssuer;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Certificates\OpenSslLeafCertificateSigner;
use App\Infrastructure\Clusters\NativeClusterRouterOperationLock;
use App\Infrastructure\DatabaseConnections\RegisteredDatabaseInspectionExecutor;
use App\Infrastructure\DatabaseServers\RemoteDatabaseServerAdmin;
use App\Infrastructure\Doctor\NativeCaddyBuildInspector;
use App\Infrastructure\Doctor\NativeCustomProxyRouteInspector;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativePrivateRouteProjectionInspector;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Doctor\NativeProjectStateInspector;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Doctor\NativeScheduleStateInspector;
use App\Infrastructure\Doctor\SharedInstalledPackageInventory;
use App\Infrastructure\Doctor\SshNodeStateInspector;
use App\Infrastructure\Files\NativeAtomicSymlinkPublisher;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Firewall\NativeRouterLanIngressReconciler;
use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use App\Infrastructure\Firewall\NativeUfwFirewallManager;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayWebDirectoryConverger;
use App\Infrastructure\Gateway\NativeGatewayCaddyInstaller;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Gateway\NativeGatewayWebConverger;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\GitHub\HttpGitHubTiaBaseline;
use App\Infrastructure\GitHub\ProcessGitHubCliToken;
use App\Infrastructure\Hibernation\CacheHibernationWakeFailureStore;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
use App\Infrastructure\Hibernation\RemoteInstanceCheckoutInspector;
use App\Infrastructure\Hibernation\RemoteInstanceRuntimeReadiness;
use App\Infrastructure\Instances\NativeDevelopmentInstanceProvisioner;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\NativeInstanceRemovalProjector;
use App\Infrastructure\Instances\NativeInstanceTransferRuntime;
use App\Infrastructure\Instances\NativeProductionInstanceProvisioner;
use App\Infrastructure\Instances\NativeProductionRouteProjector;
use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\Instances\RecordedProductionInstanceContentRetention;
use App\Infrastructure\Instances\RemoteDevelopmentDeployment;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Infrastructure\Instances\RemoteInstanceCloneCandidateInspector;
use App\Infrastructure\Instances\RemoteInstanceDestinationGuard;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use App\Infrastructure\Instances\RemoteInstanceLogReader;
use App\Infrastructure\Instances\RemoteInstanceQueueReader;
use App\Infrastructure\Instances\RemoteInstanceSqliteCloner;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Instances\RemoteRegistrationSourceManager;
use App\Infrastructure\Logs\CacheLogStreamStore;
use App\Infrastructure\Metrics\MetricsCadvisorRuntime;
use App\Infrastructure\Metrics\MetricsCadvisorSshExecutor;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterSshExecutor;
use App\Infrastructure\Metrics\MetricsPublicationManager;
use App\Infrastructure\Metrics\MetricsRuntimeHost;
use App\Infrastructure\Metrics\MetricsSshExecutor;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Metrics\NativeMetricsCadvisorLifecycle;
use App\Infrastructure\Metrics\NativeMetricsContainerRuntime;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use App\Infrastructure\Metrics\NativeServiceMetricsLifecycle;
use App\Infrastructure\Metrics\NativeServiceMetricsRuntime;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Infrastructure\Nodes\EloquentNodeRoleDependencyInspector;
use App\Infrastructure\Nodes\Metrics\GrafanaPrometheusNodeMetricsReader;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Infrastructure\Nodes\NativeNodeRoleDependentCleaner;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\RemoteNodeStorageRootPreparer;
use App\Infrastructure\Nodes\Roles\GatewayRoleBaseline;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Nodes\Roles\NativeRoleBaselineConverger;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Infrastructure\Nodes\SshManagedUserAccountResolver;
use App\Infrastructure\Nodes\SshNodeReachabilityProbe;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\LockRenewingProcessRunner;
use App\Infrastructure\Processes\NativeProcessAdmissionLock;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\NativeProcessRuntimeLease;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\PrometheusProcessRuntimeStatusIndex;
use App\Infrastructure\Processes\PrometheusProcessUsageIndex;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use App\Infrastructure\ProxyCli\ArrayProxyCliCache;
use App\Infrastructure\ProxyCli\HttpProxyCliAccountControlClient;
use App\Infrastructure\ProxyCli\NativeProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\NativeProxyCliRuntimeLifecycle;
use App\Infrastructure\ProxyCli\RecordingProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\RecordingProxyCliRuntimeLifecycle;
use App\Infrastructure\ProxyCli\ValkeyProxyCliCache;
use App\Infrastructure\Routes\NativeClusterRouterReplacementProjector;
use App\Infrastructure\Routes\NativeCustomProxyRouteProjector;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Infrastructure\Routes\NativeRouteRemovalProjector;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Infrastructure\Schedules\SshScheduleRuntimeAccountResolver;
use App\Infrastructure\SourceControl\NativeRepositoryDefaultBranchResolver;
use App\Infrastructure\Ssh\GatewaySshKeys;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshHostKeyScanner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\NativeToolInspector;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\NativeToolOperationLock;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Infrastructure\Tools\VpToolManager;
use App\Infrastructure\WebSocket\NativeWebSocketCredentialManager;
use App\Infrastructure\WebSocket\NativeWebSocketPublicationManager;
use App\Infrastructure\WebSocket\NativeWebSocketRuntimeLifecycle;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\NativeGatewayVpnConverger;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;
use App\Infrastructure\WireGuard\NativeWireGuardPeerDnsRepairer;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Support\ValidatedData;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\Console\InstallCommand;
use Laravel\Boost\Install\GuidelineComposer;
use Psr\Log\LoggerInterface;

final class ApplicationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceDestinationGuard::class => RemoteInstanceDestinationGuard::class,
        InstanceCloneCandidateInspector::class => RemoteInstanceCloneCandidateInspector::class,
        InstanceEnvironmentReader::class => RemoteInstanceEnvironmentAccess::class,
        InstanceEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        InstanceTestEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        InstanceSqliteCloner::class => RemoteInstanceSqliteCloner::class,
        InstanceOperationPreflight::class => RemoteInstanceEnvironmentAccess::class,
        InstanceSqliteSeeder::class => RemoteInstanceSqliteSeeder::class,
        InstanceTransferSource::class => RemoteInstanceTransferSource::class,
        InstanceTransferRuntime::class => NativeInstanceTransferRuntime::class,
        InstanceTransferRouteProjector::class => NativeDevelopmentRouteProjector::class,
        AgentationSiteProjection::class => RemoteAgentationSiteProjection::class,
        AppDevCaddyManager::class => RemoteAppDevCaddyManager::class,
        NodeCaddyBuilds::class => NodeCaddyBuilder::class,
        AppDevPhpFpmManager::class => RemoteAppDevPhpFpmManager::class,
        AppDevTldConverger::class => NativeAppDevTldConverger::class,
        AppDevTldRouteManager::class => RemoteAppDevTldRouteManager::class,
        DevelopmentInstanceSourceLifecycle::class => RemoteDevelopmentInstanceSourceLifecycle::class,
        DevelopmentInstanceBranchInspector::class => RemoteDevelopmentInstanceSourceLifecycle::class,
        RegistrationSourceManager::class => RemoteRegistrationSourceManager::class,
        DevelopmentInstanceSourceRemoval::class => RemoteDevelopmentInstanceSourceRemoval::class,
        ProductionInstanceContentRetention::class => RecordedProductionInstanceContentRetention::class,
        InstanceRemover::class => RemoveInstanceAction::class,
        InstanceRemovalProjector::class => NativeInstanceRemovalProjector::class,
        DevelopmentInstanceConfigurator::class => RemoteDevelopmentInstanceConfigurator::class,
        ProjectUpdateSourceMutator::class => RemoteProjectUpdateSourceMutator::class,
        ProjectUpdateProjectionMutator::class => NativeProjectUpdateProjectionMutator::class,
        DevelopmentInstanceProvisioner::class => NativeDevelopmentInstanceProvisioner::class,
        DevelopmentRouteProjector::class => NativeDevelopmentRouteProjector::class,
        ProductionInstanceProvisioner::class => NativeProductionInstanceProvisioner::class,
        ProductionDeployment::class => RemoteProductionDeployment::class,
        DevelopmentDeployment::class => RemoteDevelopmentDeployment::class,
        DeploymentStreamConnection::class => NativeDeploymentStreamConnection::class,
        ProductionInstanceSourceLifecycle::class => RemoteProductionInstanceSourceLifecycle::class,
        ProductionReleaseLayout::class => RemoteProductionInstanceSourceLifecycle::class,
        ProductionPhpRuntimeManager::class => RemoteProductionPhpRuntimeManager::class,
        ProductionRouteProjector::class => NativeProductionRouteProjector::class,
        ProductionCloneRouteProjector::class => NativeProductionRouteProjector::class,
        InstanceEnvironmentSynchronizer::class => SynchronizeInstanceEnvironmentAction::class,
        InstanceRouteEnvironmentSynchronizer::class => SynchronizeInstanceEnvironmentAction::class,
        RouteDomainProjector::class => NativeDevelopmentRouteProjector::class,
        ClusterRouterReplacementProjector::class => NativeClusterRouterReplacementProjector::class,
        RouteRemovalProjector::class => NativeRouteRemovalProjector::class,
        CustomProxyRouteProjector::class => NativeCustomProxyRouteProjector::class,
        PublicRouteEdgeProjector::class => NativePublicRouteEdgeProjector::class,
        AppProdCaddyManager::class => RemoteAppProdCaddyManager::class,
        ProjectStateInspector::class => NativeProjectStateInspector::class,
        FirewallInspector::class => NativeUfwFirewallInspector::class,
        FirewallManager::class => NativeUfwFirewallManager::class,
        GatewayVpnStateInspector::class => NativeGatewayVpnStateInspector::class,
        HostKeyScanner::class => SshHostKeyScanner::class,
        InstanceStateInspector::class => NativeInstanceStateInspector::class,
        PublicRouteEdgeInspector::class => NativePublicRouteEdgeInspector::class,
        PrivateRouteProjectionInspector::class => NativePrivateRouteProjectionInspector::class,
        CustomProxyRouteInspector::class => NativeCustomProxyRouteInspector::class,
        MetricsCredentialManager::class => NativeMetricsCredentialManager::class,
        MetricsAccessRevoker::class => NativeMetricsAccessRevoker::class,
        MetricsCredentialRuntime::class => MetricsSshExecutor::class,
        MetricsExporterLifecycle::class => NativeMetricsExporterLifecycle::class,
        MetricsExporterProjection::class => NativeMetricsExporterProjection::class,
        MetricsFirewallExpectationProvider::class => NativeMetricsFirewallExpectationProvider::class,
        MetricsExporterRuntime::class => MetricsExporterSshExecutor::class,
        ServiceMetricsLifecycle::class => NativeServiceMetricsLifecycle::class,
        ServiceMetricsRuntime::class => NativeServiceMetricsRuntime::class,
        ServiceMetricsProjection::class => ServiceMetricsProjection::class,
        MetricsCadvisorLifecycle::class => NativeMetricsCadvisorLifecycle::class,
        MetricsCadvisorRuntime::class => MetricsCadvisorSshExecutor::class,
        MetricsFleetReconciler::class => NativeMetricsFleetReconciler::class,
        MetricsPublicationManagerContract::class => MetricsPublicationManager::class,
        MetricsRoleManager::class => NativeMetricsRoleManager::class,
        MetricsRuntimeHost::class => MetricsSshExecutor::class,
        MetricsRuntimeLifecycle::class => NativeMetricsContainerRuntime::class,
        MetricsStatusReader::class => NativeMetricsStatusReader::class,
        NodeConverger::class => NativeNodeConverger::class,
        NodeStorageRootPreparer::class => RemoteNodeStorageRootPreparer::class,
        NodeReachabilityProbe::class => SshNodeReachabilityProbe::class,
        InstalledPackageInventory::class => SharedInstalledPackageInventory::class,
        NodeStateInspector::class => SshNodeStateInspector::class,
        NodeMetricsReader::class => GrafanaPrometheusNodeMetricsReader::class,
        ProcessStateInspector::class => NativeProcessStateInspector::class,
        SqliteSnapshotTransfer::class => ProtectedSqliteSnapshotTransfer::class,
        NodeRoleDependencyInspector::class => EloquentNodeRoleDependencyInspector::class,
        NodeRoleDependentCleaner::class => NativeNodeRoleDependentCleaner::class,
        NodeRoleFirewallManager::class => NativeNodeRoleFirewallManager::class,
        RouterLanIngressPublisher::class => NativeNodeRoleFirewallManager::class,
        RouterLanIngressReconciler::class => NativeRouterLanIngressReconciler::class,
        RoleBaselineConverger::class => NativeRoleBaselineConverger::class,
        GatewayPrivateDnsRoute::class => GatewayRoleBaseline::class,
        NodeAgentRuntime::class => NodeAgentSshExecutor::class,
        DatabaseServerAdmin::class => RemoteDatabaseServerAdmin::class,
        ProcessRuntimeManager::class => RemoteProcessRuntimeManager::class,
        ProcessEnvironmentProjection::class => RemoteProcessRuntimeManager::class,
        ProcessRuntimeStatusIndex::class => PrometheusProcessRuntimeStatusIndex::class,
        AgentStateView::class => CacheAgentStateView::class,
        AgentViewConverger::class => NativeAgentViewConverger::class,
        ProcessUsageIndex::class => PrometheusProcessUsageIndex::class,
        VitePortRuntime::class => RemoteVitePortRuntime::class,
        HibernationMarkerStore::class => RemoteHibernationMarkerStore::class,
        InstanceCheckoutInspector::class => RemoteInstanceCheckoutInspector::class,
        InstanceLogReader::class => RemoteInstanceLogReader::class,
        InstanceQueueReader::class => RemoteInstanceQueueReader::class,
        HibernationWakeFailureStore::class => CacheHibernationWakeFailureStore::class,
        ScheduleRuntimeAccountResolver::class => SshScheduleRuntimeAccountResolver::class,
        ScheduleRuntimeManager::class => RemoteScheduleRuntimeManager::class,
        TiaBaselineSource::class => HttpGitHubTiaBaseline::class,
        GitHubApi::class => HttpGitHubApi::class,
        GitHubCliToken::class => ProcessGitHubCliToken::class,
        RepositoryDefaultBranchResolver::class => NativeRepositoryDefaultBranchResolver::class,
        SshExecutor::class => NativeSshExecutor::class,
        DatabaseInspectionExecutor::class => RegisteredDatabaseInspectionExecutor::class,
        ClusterRouterDnsSelectionReconciler::class => NativeClusterRouterDnsSelectionReconciler::class,
        RoleStateInspector::class => NativeRoleStateInspector::class,
        CaddyBuildInspector::class => NativeCaddyBuildInspector::class,
        ScheduleStateInspector::class => NativeScheduleStateInspector::class,
        ToolInspector::class => NativeToolInspector::class,
        ToolManagerMaterializer::class => NativeToolManagerMaterializer::class,
        ToolOperationLock::class => NativeToolOperationLock::class,
        AnalyticsRoleSettingsRepository::class => NativeAnalyticsRoleSettingsRepository::class,
        AnalyticsSecretManager::class => NativeAnalyticsSecretManager::class,
        PlausibleRuntimeLifecycle::class => NativePlausibleRuntimeLifecycle::class,
        AnalyticsPublicationManager::class => NativeAnalyticsPublicationManager::class,
        AnalyticsClickhouseConfigurationManager::class => NativeAnalyticsClickhouseConfigurationManager::class,
        AnalyticsTrackingRouteProjector::class => NativeAnalyticsTrackingRouteProjector::class,
        AnalyticsStatsDriver::class => PlausibleCommunityEditionStatsDriver::class,
        AnalyticsStatsKeyStore::class => NativeAnalyticsStatsKeyStore::class,
        WebSocketCredentialManager::class => NativeWebSocketCredentialManager::class,
        WebSocketPublicationManager::class => NativeWebSocketPublicationManager::class,
        WebSocketRuntimeLifecycle::class => NativeWebSocketRuntimeLifecycle::class,
        ProxyCliAccountControlClient::class => HttpProxyCliAccountControlClient::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ArrayProxyCliCache::class);
        $this->app->singleton(ProxyCliCache::class, function ($app): ProxyCliCache {
            if ($app->environment('testing')) {
                return $app->make(ArrayProxyCliCache::class);
            }

            $slug = $app->make(ProxyCliState::class)->cacheConnection();
            $connection = is_string($slug)
                ? DatabaseConnection::query()->where('slug', $slug)->first()
                : null;

            return $connection instanceof DatabaseConnection
                ? new ValkeyProxyCliCache($connection)
                : $app->make(ArrayProxyCliCache::class);
        });
        $this->app->singleton(ProxyCliSnapshotStore::class);
        if ($this->app->environment('testing')) {
            $this->app->singleton(RecordingProxyCliRuntimeLifecycle::class);
            $this->app->singleton(RecordingProxyCliPublicationManager::class);
            $this->app->singleton(ProxyCliRuntimeLifecycle::class, static fn ($app): ProxyCliRuntimeLifecycle => $app->make(RecordingProxyCliRuntimeLifecycle::class));
            $this->app->singleton(ProxyCliPublicationManager::class, static fn ($app): ProxyCliPublicationManager => $app->make(RecordingProxyCliPublicationManager::class));
        } else {
            $this->app->bind(ProxyCliRuntimeLifecycle::class, NativeProxyCliRuntimeLifecycle::class);
            $this->app->bind(ProxyCliPublicationManager::class, NativeProxyCliPublicationManager::class);
        }

        $this->app->bind(
            SweepIdleAppDevRuntimesAction::class,
            static fn ($app): SweepIdleAppDevRuntimesAction => new SweepIdleAppDevRuntimesAction(
                policy: $app->make(DevelopmentHibernationPolicy::class),
                admissions: $app->make(ProcessAdmissionLock::class),
                runtime: $app->make(ProcessRuntimeManager::class),
                markers: $app->make(HibernationMarkerStore::class),
                checkouts: $app->make(InstanceCheckoutInspector::class),
                idleSeconds: Config::integer('orbit.hibernation.idle_seconds'),
                dependencyIdleSeconds: Config::integer('orbit.hibernation.dependency_idle_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->singleton(
            LogStreamStore::class,
            static fn ($app): CacheLogStreamStore => new CacheLogStreamStore(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->singleton(
            NodeLocks::class,
            static fn ($app): NodeLocks => new NodeLocks(
                $app->make(CacheManager::class)->build(NodeLocks::storeConfiguration(Config::string('orbit.home'))),
                console: $app->runningInConsole(),
            ),
        );
        $this->app->bind(
            ProcessRunner::class,
            static fn ($app): ProcessRunner => new LockRenewingProcessRunner(
                $app->make(NativeProcessRunner::class),
                $app->make(NodeLocks::class),
            ),
        );
        $this->app->singleton(NodeRoleConvergeLock::class);
        $this->app->singleton(
            CacheAgentStateView::class,
            static fn ($app): CacheAgentStateView => new CacheAgentStateView(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->bind(
            AgentViewSubscriber::class,
            static fn ($app): AgentViewSubscriber => new AgentViewSubscriber(
                socket: new StreamWebSocketClient,
                credentials: $app->make(WebSocketCredentialManager::class),
                view: $app->make(CacheAgentStateView::class),
                signer: $app->make(PresenceChannelSigner::class),
                log: $app->make(LoggerInterface::class),
                caPath: rtrim(string: Config::string('orbit.home'), characters: '/').'/ca/root.pem',
                commit: static function () use ($app): ?string {
                    $result = $app->make(ProcessRunner::class)->run(
                        new ProcessInvocation(['git', '-C', base_path(), 'rev-parse', 'HEAD'], timeout: 10.0),
                    );

                    return $result->succeeded() ? trim($result->stdout) : null;
                },
                clock: CacheAgentStateView::now(...),
                sleep: static function (float $seconds): void {
                    usleep((int) ($seconds * 1_000_000));
                },
                publisher: new ProcessAgentViewPublisher(
                    command: [PHP_BINARY, base_path('artisan'), 'orbit:agent-view-publish'],
                    log: $app->make(LoggerInterface::class),
                    clock: CacheAgentStateView::now(...),
                    workingDirectory: base_path(),
                ),
            ),
        );
        $this->app->bind(
            RuntimeHibernatorConverger::class,
            static fn ($app): NativeRuntimeHibernatorConverger => new NativeRuntimeHibernatorConverger(
                processes: $app->make(ProcessRunner::class),
                sweepSeconds: Config::integer('orbit.hibernation.sweep_seconds'),
            ),
        );
        $this->app->bind(
            InstanceRuntimeReadiness::class,
            static fn ($app): RemoteInstanceRuntimeReadiness => new RemoteInstanceRuntimeReadiness(
                runtime: $app->make(ProcessRuntimeManager::class),
                ssh: $app->make(SshExecutor::class),
                keys: $app->make(SshKeyProvider::class),
                knownHosts: $app->make(KnownHostsStore::class),
                timeoutSeconds: Config::integer('orbit.hibernation.wake_timeout_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->bind(
            RemoteInstanceCheckoutInspector::class,
            static fn ($app): RemoteInstanceCheckoutInspector => new RemoteInstanceCheckoutInspector(
                ssh: $app->make(SshExecutor::class),
                keys: $app->make(SshKeyProvider::class),
                knownHosts: $app->make(KnownHostsStore::class),
                accounts: $app->make(ManagedUserAccountResolver::class),
                restoreTimeoutSeconds: Config::integer('orbit.hibernation.cold_wake_timeout_seconds'),
            ),
        );
        $this->app->singleton(ManagedUserAccountResolver::class, SshManagedUserAccountResolver::class);
        $this->app->scoped(NodeProvisioningLock::class, NativeNodeProvisioningLock::class);
        $this->app->scoped(ToolManagerScopeLock::class, NativeToolManagerScopeLock::class);
        $this->app->scoped(
            MetricsCredentialOperationLock::class,
            static fn (): MetricsCredentialOperationLock => new NativeMetricsCredentialOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/')
                    .'/locks/metrics-credentials',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            NodeCaddyBuildLock::class,
            static fn (): NodeCaddyBuildLock => new NodeCaddyBuildLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/caddy-build',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->bind(
            NodeCaddyfileRenderer::class,
            static fn (): NodeCaddyfileRenderer => new NodeCaddyfileRenderer([
                app(GatewayWebCaddySiteSource::class),
                app(MetricsCaddySiteSource::class),
                app(ServiceMetricsCaddySiteSource::class),
                app(RouteCaddySiteSource::class),
                app(WebSocketCaddySiteSource::class),
                app(AnalyticsCaddySiteSource::class),
                app(ProxyCliCaddySiteSource::class),
            ]),
        );
        $this->app->scoped(
            ClusterRouterOperationLock::class,
            static fn (): ClusterRouterOperationLock => new NativeClusterRouterOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/cluster-router',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            InstanceEnvironmentOperationLock::class,
            static fn (): InstanceEnvironmentOperationLock => new NativeInstanceEnvironmentOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/')
                    .'/locks/app-instance-environment',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            ProcessAdmissionLock::class,
            static fn (): ProcessAdmissionLock => new NativeProcessAdmissionLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/process-admission',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(ProcessRuntimeLease::class, NativeProcessRuntimeLease::class);
        $this->app->scoped(
            DevelopmentProjectionOperationLock::class,
            static fn (): DevelopmentProjectionOperationLock => new NativeDevelopmentProjectionOperationLock(
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                deadline: app(CommandDeadline::class),
            ),
        );
        // Shared for one request so the Metrics baseline's removal outcome
        // reaches the disable response instead of being inferred a second time.
        $this->app->scoped(MetricsPublicationReport::class);
        $this->app->scoped(NodeRoleFollowUpReport::class);
        // Scoped so the websocket role lookup it performs happens at most
        // once per request, and only when something actually asks for it.
        $this->app->scoped(
            RealtimeConnection::class,
            static fn ($app): RealtimeConnection => new RealtimeConnection(
                credentials: $app->make(WebSocketCredentialManager::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );

        if (class_exists(GuidelineComposer::class)) {
            $this->app->singleton(GuidelineComposer::class, GatewayGuidelineComposer::class);
            $this->app->singleton(InstallCommand::class, GatewayBoostInstallCommand::class);
        }

        $this->app->singleton(
            DnsmasqPrivateDnsManager::class,
            static fn (): DnsmasqPrivateDnsManager => new DnsmasqPrivateDnsManager(
                processes: app(ProcessRunner::class),
                renderer: app(DevelopmentDnsConfigRenderer::class),
                activateListener: true,
                vpnSettings: app(VpnSettings::class),
                ssh: app(SshExecutor::class),
                keys: app(SshKeyProvider::class),
                knownHosts: app(KnownHostsStore::class),
            ),
        );
        $this->app->singleton(PrivateDnsManager::class, static fn (): PrivateDnsManager => app(DnsmasqPrivateDnsManager::class));
        $this->app->singleton(CommandDeadline::class);
        $this->app->singleton(ToolCommandBudget::class);
        $this->app->scoped(MetricsReconcileDeferral::class);
        $this->app->singleton(
            ToolManagerRegistry::class,
            static fn (): ToolManagerRegistry => new ToolManagerRegistry([
                app(AptToolManager::class),
                app(VpToolManager::class),
                app(ComposerToolManager::class),
                app(HomebrewToolManager::class),
                app(HomebrewCaskToolManager::class),
            ]),
        );
        $this->app->singleton(
            AppDevSourceOperationLock::class,
            static fn (): AppDevSourceOperationLock => new NativeAppDevSourceOperationLock(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/app-dev-source',
            ),
        );
        $this->app->singleton(
            DevelopmentInstanceSourceFinalizer::class,
            static fn (): DevelopmentInstanceSourceFinalizer => app(RemoteDevelopmentInstanceSourceRemoval::class),
        );
        $this->app->singleton(
            LeafCertificateSigner::class,
            static fn (): LeafCertificateSigner => new OpenSslLeafCertificateSigner(
                processes: app(ProcessRunner::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewayCertificateIssuer::class,
            static fn (): GatewayCertificateIssuer => new OpenSslGatewayCertificateIssuer(
                processes: app(ProcessRunner::class),
                validator: app(OpenSslGatewayCertificateValidator::class),
                links: app(NativeAtomicSymlinkPublisher::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewayVpnConverger::class,
            static fn (): GatewayVpnConverger => new NativeGatewayVpnConverger(
                renderer: app(WireGuardServerConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                processes: app(ProcessRunner::class),
                firewallParser: app(UfwStatusParser::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewayWebConverger::class,
            static fn (): GatewayWebConverger => new NativeGatewayWebConverger(
                certificates: app(GatewayCertificateIssuer::class),
                fpmRenderer: app(GatewayFpmConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                checkout: new GatewayCheckoutAccessConverger(
                    processes: app(ProcessRunner::class),
                    checkoutPath: rtrim(string: Config::string('orbit.gateway_checkout'), characters: '/'),
                ),
                webDirectory: new GatewayWebDirectoryConverger(
                    processes: app(ProcessRunner::class),
                    webRoot: Config::string('orbit.gateway_web'),
                ),
                certificatePublisher: new NativeGatewayCertificatePublisher(
                    processes: app(ProcessRunner::class),
                    orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                ),
                fpm: new NativeGatewayFpmConverger(app(ProcessRunner::class)),
                builds: app(NodeCaddyBuilds::class),
                caddyInstaller: new NativeGatewayCaddyInstaller(app(ProcessRunner::class)),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                checkoutPath: rtrim(string: Config::string('orbit.gateway_checkout'), characters: '/'),
                hibernator: app(RuntimeHibernatorConverger::class),
                agentView: app(AgentViewConverger::class),
            ),
        );
        $this->app->singleton(
            GatewaySelfAccessConverger::class,
            static fn (): GatewaySelfAccessConverger => new NativeGatewaySelfAccessConverger(
                processes: app(ProcessRunner::class),
                knownHosts: app(KnownHostsStore::class),
                sshKeys: app(SshKeyProvider::class),
                homeDirectory: self::resolveManagedUserHomeDirectory(...),
            ),
        );
        $this->app->singleton(
            BootstrapGatewayAction::class,
            static fn (): BootstrapGatewayAction => new BootstrapGatewayAction(
                assignRole: app(AssignRoleAction::class),
                identity: app(GatewayBootstrapIdentityValidator::class),
                operatingSystem: app(GatewayOperatingSystemGuard::class),
                vpnSettings: app(VpnSettings::class),
                processes: app(ProcessRunner::class),
                files: app(ProtectedFileWriter::class),
                vpn: app(GatewayVpnConverger::class),
                web: app(GatewayWebConverger::class),
                selfAccess: app(GatewaySelfAccessConverger::class),
                dns: app(PrivateDnsManager::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            KnownHostsRepository::class,
            static fn (): KnownHostsRepository => new KnownHostsRepository(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/ssh/known_hosts',
            ),
        );
        $this->app->alias(KnownHostsRepository::class, KnownHostsStore::class);
        $this->app->singleton(
            GatewaySshKeys::class,
            static fn (): GatewaySshKeys => new GatewaySshKeys(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/ssh/id_ed25519',
            ),
        );
        $this->app->alias(GatewaySshKeys::class, SshKeyProvider::class);
        $this->app->singleton(
            VpnConfigurationRepository::class,
            static fn (): VpnConfigurationRepository => new VpnConfigurationRepository(
                app(VpnSettings::class),
                rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            NativeGatewayPeerProjectionManager::class,
            static fn (): NativeGatewayPeerProjectionManager => new NativeGatewayPeerProjectionManager(
                configuration: app(VpnConfigurationRepository::class),
                serverRenderer: app(WireGuardServerConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                processes: app(ProcessRunner::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                ssh: app(SshExecutor::class),
                keys: app(SshKeyProvider::class),
                knownHosts: app(KnownHostsStore::class),
            ),
        );
        $this->app->alias(NativeGatewayPeerProjectionManager::class, GatewayPeerProjectionManager::class);
        $this->app->singleton(
            NativeWireGuardPeerConverger::class,
            static fn (): NativeWireGuardPeerConverger => new NativeWireGuardPeerConverger(
                configuration: app(VpnConfigurationRepository::class),
                gatewayPeers: app(GatewayPeerProjectionManager::class),
                ssh: app(SshExecutor::class),
            ),
        );
        $this->app->alias(NativeWireGuardPeerConverger::class, WireGuardPeerConverger::class);
        $this->app->singleton(
            NativeWireGuardPeerDnsRepairer::class,
            static fn (): NativeWireGuardPeerDnsRepairer => new NativeWireGuardPeerDnsRepairer(
                configuration: app(VpnConfigurationRepository::class),
                ssh: app(SshExecutor::class),
                sshKeys: app(SshKeyProvider::class),
                knownHosts: app(KnownHostsStore::class),
            ),
        );
        $this->app->alias(NativeWireGuardPeerDnsRepairer::class, WireGuardPeerDnsRepairer::class);
    }

    public function boot(ActivityPropertiesObserver $activityPropertiesObserver): void
    {
        $cache = config('cache');
        if (! is_array($cache)) {
            throw new \UnexpectedValueException('The Gateway cache configuration is invalid.');
        }
        $cache = ValidatedData::object($cache);
        if (! $this->app->runningConsoleCommand(GatewayCacheStore::RecoveryCommands)) {
            GatewayCacheStore::assertSupported($cache, $this->app->environment(), $this->app->configurationIsCached());
        }
        Activity::observe($activityPropertiesObserver);
        Activity::observe(ActivityBroadcastObserver::class);
        // Reverb event bodies carry text as UTF-8, so non-ASCII log lines keep their length (ADR 0153).
        $this->app->make(BroadcastManager::class)->extend(
            'reverb',
            fn ($app, array $config): ReverbBroadcaster => new ReverbBroadcaster($this->pusher($config), (bool) ($config['jsonp'] ?? false)),
        );
        Relation::morphMap([
            'instance' => Instance::class,
        ]);
    }

    private static function resolveManagedUserHomeDirectory(string $user): string|false
    {
        $account = posix_getpwnam($user);

        return is_array($account) ? (string) $account['dir'] : false;
    }
}
