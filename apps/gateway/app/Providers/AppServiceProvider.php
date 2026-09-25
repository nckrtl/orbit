<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Actions\AppInstances\SynchronizeAppInstanceEnvironmentAction;
use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Actions\Nodes\AssignRoleAction;
use App\Console\GatewayBoostInstallCommand;
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
use App\Domain\AppInstances\AppInstanceCloneCandidateInspector;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentSynchronizer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\AppInstances\Logs\AppInstanceLogReader;
use App\Domain\AppInstances\ProductionAppInstanceProvisioner;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionCloneRouteProjector;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\AppInstances\Queue\AppInstanceQueueReader;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\AppInstances\Removal\ProductionAppInstanceContentRetention;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRouteProjector;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRuntime;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\AppProd\AppProdCaddyManager;
use App\Domain\AppProd\AppProdPhpFpmManager;
use App\Domain\Apps\AppUpdateProjectionMutator;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\ManagedMysqlUserProvisioner;
use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\CaddyBuildInspector;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\GitHub\GitHubApi;
use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
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
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Metrics\ServiceMetricsLifecycle;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessUsageIndex;
use App\Domain\ProxyCli\ProxyCliAccountControlClient;
use App\Domain\ProxyCli\ProxyCliCache;
use App\Domain\ProxyCli\ProxyCliManagementClient;
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
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
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
use App\Infrastructure\AppInstances\NativeAppInstanceEnvironmentOperationLock;
use App\Infrastructure\AppInstances\NativeAppInstanceRemovalProjector;
use App\Infrastructure\AppInstances\NativeAppInstanceTransferRuntime;
use App\Infrastructure\AppInstances\NativeDevelopmentAppInstanceProvisioner;
use App\Infrastructure\AppInstances\NativeDevelopmentRouteProjector;
use App\Infrastructure\AppInstances\NativeProductionAppInstanceProvisioner;
use App\Infrastructure\AppInstances\NativeProductionRouteProjector;
use App\Infrastructure\AppInstances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\AppInstances\RecordedProductionAppInstanceContentRetention;
use App\Infrastructure\AppInstances\RemoteAppInstanceCloneCandidateInspector;
use App\Infrastructure\AppInstances\RemoteAppInstanceDestinationGuard;
use App\Infrastructure\AppInstances\RemoteAppInstanceEnvironmentAccess;
use App\Infrastructure\AppInstances\RemoteAppInstanceLogReader;
use App\Infrastructure\AppInstances\RemoteAppInstanceQueueReader;
use App\Infrastructure\AppInstances\RemoteAppInstanceSqliteSeeder;
use App\Infrastructure\AppInstances\RemoteAppInstanceTransferSource;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceConfigurator;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceSourceLifecycle;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceSourceRemoval;
use App\Infrastructure\AppInstances\RemoteProductionAppInstanceSourceLifecycle;
use App\Infrastructure\AppInstances\RemoteProductionDeployment;
use App\Infrastructure\AppInstances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\AppInstances\RemoteRegistrationSourceManager;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Infrastructure\AppProd\RemoteAppProdPhpFpmManager;
use App\Infrastructure\Apps\NativeAppUpdateProjectionMutator;
use App\Infrastructure\Apps\RemoteAppUpdateSourceMutator;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\AppCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ProxyCliCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateIssuer;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Certificates\OpenSslLeafCertificateSigner;
use App\Infrastructure\Clusters\NativeClusterRouterOperationLock;
use App\Infrastructure\DatabaseConnections\RegisteredDatabaseInspectionExecutor;
use App\Infrastructure\DatabaseConnections\RemoteManagedMysqlUserProvisioner;
use App\Infrastructure\Doctor\NativeAppStateInspector;
use App\Infrastructure\Doctor\NativeCaddyBuildInspector;
use App\Infrastructure\Doctor\NativeCustomProxyRouteInspector;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativePrivateRouteProjectionInspector;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Doctor\NativeScheduleStateInspector;
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
use App\Infrastructure\Hibernation\CacheHibernationWakeFailureStore;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Hibernation\RemoteAppInstanceCheckoutInspector;
use App\Infrastructure\Hibernation\RemoteAppInstanceRuntimeReadiness;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
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
use App\Infrastructure\Nodes\RemoteNodeStorageRootPreparer;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Nodes\Roles\NativeRoleBaselineConverger;
use App\Infrastructure\Nodes\SshManagedUserAccountResolver;
use App\Infrastructure\Nodes\SshNodeReachabilityProbe;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\NativeProcessAdmissionLock;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\NativeProcessRuntimeLease;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\PrometheusProcessRuntimeStatusIndex;
use App\Infrastructure\Processes\PrometheusProcessUsageIndex;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\ProxyCli\ArrayProxyCliCache;
use App\Infrastructure\ProxyCli\HttpCliProxyApiClient;
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
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\NativeToolInspector;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\NativeToolOperationLock;
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
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\Console\InstallCommand;
use Laravel\Boost\Install\GuidelineComposer;
use Psr\Log\LoggerInterface;

final class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AppInstanceDestinationGuard::class => RemoteAppInstanceDestinationGuard::class,
        AppInstanceCloneCandidateInspector::class => RemoteAppInstanceCloneCandidateInspector::class,
        AppInstanceEnvironmentReader::class => RemoteAppInstanceEnvironmentAccess::class,
        AppInstanceEnvironmentWriter::class => RemoteAppInstanceEnvironmentAccess::class,
        AppInstanceOperationPreflight::class => RemoteAppInstanceEnvironmentAccess::class,
        AppInstanceSqliteSeeder::class => RemoteAppInstanceSqliteSeeder::class,
        AppInstanceTransferSource::class => RemoteAppInstanceTransferSource::class,
        AppInstanceTransferRuntime::class => NativeAppInstanceTransferRuntime::class,
        AppInstanceTransferRouteProjector::class => NativeDevelopmentRouteProjector::class,
        AgentationSiteProjection::class => RemoteAgentationSiteProjection::class,
        AppDevCaddyManager::class => RemoteAppDevCaddyManager::class,
        NodeCaddyBuilds::class => NodeCaddyBuilder::class,
        AppDevPhpFpmManager::class => RemoteAppDevPhpFpmManager::class,
        AppDevTldConverger::class => NativeAppDevTldConverger::class,
        AppDevTldRouteManager::class => RemoteAppDevTldRouteManager::class,
        DevelopmentAppInstanceSourceLifecycle::class => RemoteDevelopmentAppInstanceSourceLifecycle::class,
        RegistrationSourceManager::class => RemoteRegistrationSourceManager::class,
        DevelopmentAppInstanceSourceRemoval::class => RemoteDevelopmentAppInstanceSourceRemoval::class,
        ProductionAppInstanceContentRetention::class => RecordedProductionAppInstanceContentRetention::class,
        AppInstanceRemover::class => RemoveAppInstanceAction::class,
        AppInstanceRemovalProjector::class => NativeAppInstanceRemovalProjector::class,
        DevelopmentAppInstanceConfigurator::class => RemoteDevelopmentAppInstanceConfigurator::class,
        AppUpdateSourceMutator::class => RemoteAppUpdateSourceMutator::class,
        AppUpdateProjectionMutator::class => NativeAppUpdateProjectionMutator::class,
        DevelopmentAppInstanceProvisioner::class => NativeDevelopmentAppInstanceProvisioner::class,
        DevelopmentRouteProjector::class => NativeDevelopmentRouteProjector::class,
        ProductionAppInstanceProvisioner::class => NativeProductionAppInstanceProvisioner::class,
        ProductionDeployment::class => RemoteProductionDeployment::class,
        DeploymentStreamConnection::class => NativeDeploymentStreamConnection::class,
        ProductionAppInstanceSourceLifecycle::class => RemoteProductionAppInstanceSourceLifecycle::class,
        ProductionReleaseLayout::class => RemoteProductionAppInstanceSourceLifecycle::class,
        ProductionPhpRuntimeManager::class => RemoteProductionPhpRuntimeManager::class,
        ProductionRouteProjector::class => NativeProductionRouteProjector::class,
        ProductionCloneRouteProjector::class => NativeProductionRouteProjector::class,
        AppInstanceEnvironmentSynchronizer::class => SynchronizeAppInstanceEnvironmentAction::class,
        AppInstanceRouteEnvironmentSynchronizer::class => SynchronizeAppInstanceEnvironmentAction::class,
        RouteDomainProjector::class => NativeDevelopmentRouteProjector::class,
        ClusterRouterReplacementProjector::class => NativeClusterRouterReplacementProjector::class,
        RouteRemovalProjector::class => NativeRouteRemovalProjector::class,
        CustomProxyRouteProjector::class => NativeCustomProxyRouteProjector::class,
        PublicRouteEdgeProjector::class => NativePublicRouteEdgeProjector::class,
        AppProdCaddyManager::class => RemoteAppProdCaddyManager::class,
        AppProdPhpFpmManager::class => RemoteAppProdPhpFpmManager::class,
        AppStateInspector::class => NativeAppStateInspector::class,
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
        NodeAgentRuntime::class => NodeAgentSshExecutor::class,
        ManagedMysqlUserProvisioner::class => RemoteManagedMysqlUserProvisioner::class,
        ProcessRuntimeManager::class => RemoteProcessRuntimeManager::class,
        ProcessRuntimeStatusIndex::class => PrometheusProcessRuntimeStatusIndex::class,
        AgentStateView::class => CacheAgentStateView::class,
        AgentViewConverger::class => NativeAgentViewConverger::class,
        ProcessUsageIndex::class => PrometheusProcessUsageIndex::class,
        VitePortRuntime::class => RemoteVitePortRuntime::class,
        HibernationMarkerStore::class => RemoteHibernationMarkerStore::class,
        AppInstanceCheckoutInspector::class => RemoteAppInstanceCheckoutInspector::class,
        AppInstanceLogReader::class => RemoteAppInstanceLogReader::class,
        AppInstanceQueueReader::class => RemoteAppInstanceQueueReader::class,
        HibernationWakeFailureStore::class => CacheHibernationWakeFailureStore::class,
        ScheduleRuntimeAccountResolver::class => SshScheduleRuntimeAccountResolver::class,
        ScheduleRuntimeManager::class => RemoteScheduleRuntimeManager::class,
        GitHubApi::class => HttpGitHubApi::class,
        RepositoryDefaultBranchResolver::class => NativeRepositoryDefaultBranchResolver::class,
        ProcessRunner::class => NativeProcessRunner::class,
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
        ProxyCliManagementClient::class => HttpCliProxyApiClient::class,
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
                policy: $app->make(AppDevHibernationPolicy::class),
                admissions: $app->make(ProcessAdmissionLock::class),
                runtime: $app->make(ProcessRuntimeManager::class),
                markers: $app->make(HibernationMarkerStore::class),
                checkouts: $app->make(AppInstanceCheckoutInspector::class),
                idleSeconds: (int) config('orbit.hibernation.idle_seconds'),
                dependencyIdleSeconds: (int) config('orbit.hibernation.dependency_idle_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->singleton(
            LogStreamStore::class,
            static fn ($app): CacheLogStreamStore => new CacheLogStreamStore(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration((string) config('orbit.home'))),
            ),
        );
        $this->app->singleton(
            CacheAgentStateView::class,
            static fn ($app): CacheAgentStateView => new CacheAgentStateView(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration((string) config('orbit.home'))),
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
                caPath: rtrim(string: (string) config('orbit.home'), characters: '/').'/ca/root.pem',
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
                sweepSeconds: (int) config('orbit.hibernation.sweep_seconds'),
            ),
        );
        $this->app->bind(
            AppInstanceRuntimeReadiness::class,
            static fn ($app): RemoteAppInstanceRuntimeReadiness => new RemoteAppInstanceRuntimeReadiness(
                runtime: $app->make(ProcessRuntimeManager::class),
                ssh: $app->make(SshExecutor::class),
                keys: $app->make(SshKeyProvider::class),
                knownHosts: $app->make(KnownHostsStore::class),
                timeoutSeconds: (int) config('orbit.hibernation.wake_timeout_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->bind(
            RemoteAppInstanceCheckoutInspector::class,
            static fn ($app): RemoteAppInstanceCheckoutInspector => new RemoteAppInstanceCheckoutInspector(
                ssh: $app->make(SshExecutor::class),
                keys: $app->make(SshKeyProvider::class),
                knownHosts: $app->make(KnownHostsStore::class),
                accounts: $app->make(ManagedUserAccountResolver::class),
                restoreTimeoutSeconds: (int) config('orbit.hibernation.cold_wake_timeout_seconds'),
            ),
        );
        $this->app->singleton(ManagedUserAccountResolver::class, SshManagedUserAccountResolver::class);
        $this->app->scoped(NodeProvisioningLock::class, NativeNodeProvisioningLock::class);
        $this->app->scoped(ToolManagerScopeLock::class, NativeToolManagerScopeLock::class);
        $this->app->scoped(
            MetricsCredentialOperationLock::class,
            static fn (): MetricsCredentialOperationLock => new NativeMetricsCredentialOperationLock(
                directory: rtrim(string: (string) config('orbit.home'), characters: '/')
                    .'/locks/metrics-credentials',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            NodeCaddyBuildLock::class,
            static fn (): NodeCaddyBuildLock => new NodeCaddyBuildLock(
                directory: rtrim(string: (string) config('orbit.home'), characters: '/').'/locks/caddy-build',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->bind(
            NodeCaddyfileRenderer::class,
            static fn (): NodeCaddyfileRenderer => new NodeCaddyfileRenderer([
                app(GatewayWebCaddySiteSource::class),
                app(MetricsCaddySiteSource::class),
                app(ServiceMetricsCaddySiteSource::class),
                app(AppCaddySiteSource::class),
                app(WebSocketCaddySiteSource::class),
                app(AnalyticsCaddySiteSource::class),
                app(ProxyCliCaddySiteSource::class),
            ]),
        );
        $this->app->scoped(
            ClusterRouterOperationLock::class,
            static fn (): ClusterRouterOperationLock => new NativeClusterRouterOperationLock(
                directory: rtrim(string: (string) config('orbit.home'), characters: '/').'/locks/cluster-router',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            AppInstanceEnvironmentOperationLock::class,
            static fn (): AppInstanceEnvironmentOperationLock => new NativeAppInstanceEnvironmentOperationLock(
                directory: rtrim(string: (string) config('orbit.home'), characters: '/')
                    .'/locks/app-instance-environment',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(
            ProcessAdmissionLock::class,
            static fn (): ProcessAdmissionLock => new NativeProcessAdmissionLock(
                directory: rtrim(string: (string) config('orbit.home'), characters: '/').'/locks/process-admission',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->scoped(ProcessRuntimeLease::class, NativeProcessRuntimeLease::class);
        $this->app->scoped(
            DevelopmentProjectionOperationLock::class,
            static fn (): DevelopmentProjectionOperationLock => new NativeDevelopmentProjectionOperationLock(
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
                deadline: app(CommandDeadline::class),
            ),
        );
        // Shared for one request so the Metrics baseline's removal outcome
        // reaches the disable response instead of being inferred a second time.
        $this->app->scoped(MetricsPublicationReport::class);
        // Scoped so the websocket role lookup it performs happens at most
        // once per request, and only when something actually asks for it.
        $this->app->scoped(
            RealtimeConnection::class,
            static fn ($app): RealtimeConnection => new RealtimeConnection(
                credentials: $app->make(WebSocketCredentialManager::class),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
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
                renderer: app(AppDevDnsConfigRenderer::class),
                activateListener: true,
                vpnSettings: app(VpnSettings::class),
                ssh: app(SshExecutor::class),
                keys: app(SshKeyProvider::class),
                knownHosts: app(KnownHostsStore::class),
            ),
        );
        $this->app->singleton(PrivateDnsManager::class, static fn (): PrivateDnsManager => app(DnsmasqPrivateDnsManager::class));
        $this->app->singleton(CommandDeadline::class);
        $this->app->singleton(
            ToolManagerRegistry::class,
            static fn (): ToolManagerRegistry => new ToolManagerRegistry([
                app(AptToolManager::class),
                app(VpToolManager::class),
                app(ComposerToolManager::class),
                app(HomebrewToolManager::class),
            ]),
        );
        $this->app->singleton(
            AppDevSourceOperationLock::class,
            static fn (): AppDevSourceOperationLock => new NativeAppDevSourceOperationLock(
                rtrim(string: (string) config('orbit.home'), characters: '/').'/locks/app-dev-source',
            ),
        );
        $this->app->singleton(
            DevelopmentAppInstanceSourceFinalizer::class,
            static fn (): DevelopmentAppInstanceSourceFinalizer => app(RemoteDevelopmentAppInstanceSourceRemoval::class),
        );
        $this->app->singleton(
            LeafCertificateSigner::class,
            static fn (): LeafCertificateSigner => new OpenSslLeafCertificateSigner(
                processes: app(ProcessRunner::class),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewayCertificateIssuer::class,
            static fn (): GatewayCertificateIssuer => new OpenSslGatewayCertificateIssuer(
                processes: app(ProcessRunner::class),
                validator: app(OpenSslGatewayCertificateValidator::class),
                links: app(NativeAtomicSymlinkPublisher::class),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewayVpnConverger::class,
            static fn (): GatewayVpnConverger => new NativeGatewayVpnConverger(
                renderer: app(WireGuardServerConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                processes: app(ProcessRunner::class),
                firewallParser: app(UfwStatusParser::class),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
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
                    checkoutPath: rtrim(string: (string) config('orbit.gateway_checkout'), characters: '/'),
                ),
                webDirectory: new GatewayWebDirectoryConverger(
                    processes: app(ProcessRunner::class),
                    webRoot: (string) config('orbit.gateway_web'),
                ),
                certificatePublisher: new NativeGatewayCertificatePublisher(
                    processes: app(ProcessRunner::class),
                    orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
                ),
                fpm: new NativeGatewayFpmConverger(app(ProcessRunner::class)),
                builds: app(NodeCaddyBuilds::class),
                caddyInstaller: new NativeGatewayCaddyInstaller(app(ProcessRunner::class)),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
                checkoutPath: rtrim(string: (string) config('orbit.gateway_checkout'), characters: '/'),
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
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            KnownHostsRepository::class,
            static fn (): KnownHostsRepository => new KnownHostsRepository(
                rtrim(string: (string) config('orbit.home'), characters: '/').'/ssh/known_hosts',
            ),
        );
        $this->app->alias(KnownHostsRepository::class, KnownHostsStore::class);
        $this->app->singleton(
            GatewaySshKeys::class,
            static fn (): GatewaySshKeys => new GatewaySshKeys(
                rtrim(string: (string) config('orbit.home'), characters: '/').'/ssh/id_ed25519',
            ),
        );
        $this->app->alias(GatewaySshKeys::class, SshKeyProvider::class);
        $this->app->singleton(
            VpnConfigurationRepository::class,
            static fn (): VpnConfigurationRepository => new VpnConfigurationRepository(
                app(VpnSettings::class),
                rtrim(string: (string) config('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            NativeGatewayPeerProjectionManager::class,
            static fn (): NativeGatewayPeerProjectionManager => new NativeGatewayPeerProjectionManager(
                configuration: app(VpnConfigurationRepository::class),
                serverRenderer: app(WireGuardServerConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                processes: app(ProcessRunner::class),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
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
        Activity::observe($activityPropertiesObserver);
        Relation::morphMap([
            'instance' => AppInstance::class,
        ]);
    }

    private static function resolveManagedUserHomeDirectory(string $user): string|false
    {
        $account = posix_getpwnam($user);

        return is_array($account) ? (string) $account['dir'] : false;
    }
}
