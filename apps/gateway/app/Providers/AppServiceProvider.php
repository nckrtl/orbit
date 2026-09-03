<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Actions\Nodes\AssignRoleAction;
use App\Console\GatewayBoostInstallCommand;
use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevCertificateManager;
use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\AppDevRuntimeConverger;
use App\Domain\AppDev\AppDevSourceManager;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\RegisteredWorktreeInspector;
use App\Domain\AppProd\AppProdCaddyManager;
use App\Domain\AppProd\AppProdPhpFpmManager;
use App\Domain\AppProd\AppProdRuntimeConverger;
use App\Domain\AppProd\AppProdSourceManager;
use App\Domain\AppProd\AppProdUserManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Doctor\WorkspaceStateInspector;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Metrics\MetricsCredentialManager;
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
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Activity\ActivityPropertiesObserver;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeAppDevRuntimeConverger;
use App\Infrastructure\AppDev\NativeAppDevSourceOperationLock;
use App\Infrastructure\AppDev\NativeAppDevTldConverger;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevSourceManager;
use App\Infrastructure\AppDev\RemoteAppDevTldRouteManager;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceSourceLifecycle;
use App\Infrastructure\AppInstances\RemoteRegisteredWorktreeInspector;
use App\Infrastructure\AppProd\NativeAppProdRuntimeConverger;
use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Infrastructure\AppProd\RemoteAppProdPhpFpmManager;
use App\Infrastructure\AppProd\RemoteAppProdSourceManager;
use App\Infrastructure\AppProd\RemoteAppProdUserManager;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateIssuer;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Certificates\OpenSslLeafCertificateSigner;
use App\Infrastructure\Doctor\NativeAppStateInspector;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Doctor\NativeWorkspaceStateInspector;
use App\Infrastructure\Doctor\SshNodeStateInspector;
use App\Infrastructure\Files\NativeAtomicSymlinkPublisher;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use App\Infrastructure\Firewall\NativeUfwFirewallManager;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\NativeGatewayCaddyConverger;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Gateway\NativeGatewayWebConverger;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterSshExecutor;
use App\Infrastructure\Metrics\MetricsPublicationManager;
use App\Infrastructure\Metrics\MetricsRuntimeHost;
use App\Infrastructure\Metrics\MetricsSshExecutor;
use App\Infrastructure\Metrics\NativeMetricsContainerRuntime;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use App\Infrastructure\Nodes\EloquentNodeRoleDependencyInspector;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Infrastructure\Nodes\NativeNodeRoleDependentCleaner;
use App\Infrastructure\Nodes\RemoteNodeStorageRootPreparer;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Nodes\Roles\NativeRoleBaselineConverger;
use App\Infrastructure\Nodes\SshManagedUserAccountResolver;
use App\Infrastructure\Nodes\SshNodeReachabilityProbe;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
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
use App\Infrastructure\Tools\EloquentNodeRoleToolIntentGuard;
use App\Infrastructure\Tools\NativeToolInspector;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\NativeToolOperationLock;
use App\Infrastructure\Tools\VpToolManager;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\NativeGatewayVpnConverger;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Activity;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\Console\InstallCommand;
use Laravel\Boost\Install\GuidelineComposer;

final class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        AppDevCaddyManager::class => RemoteAppDevCaddyManager::class,
        AppDevCertificateManager::class => RemoteAppDevCertificateManager::class,
        AppDevPhpFpmManager::class => RemoteAppDevPhpFpmManager::class,
        AppDevRuntimeConverger::class => NativeAppDevRuntimeConverger::class,
        AppDevTldConverger::class => NativeAppDevTldConverger::class,
        AppDevTldRouteManager::class => RemoteAppDevTldRouteManager::class,
        AppDevSourceManager::class => RemoteAppDevSourceManager::class,
        DevelopmentAppInstanceSourceLifecycle::class => RemoteDevelopmentAppInstanceSourceLifecycle::class,
        RegisteredWorktreeInspector::class => RemoteRegisteredWorktreeInspector::class,
        AppProdCaddyManager::class => RemoteAppProdCaddyManager::class,
        AppProdPhpFpmManager::class => RemoteAppProdPhpFpmManager::class,
        AppProdRuntimeConverger::class => NativeAppProdRuntimeConverger::class,
        AppProdSourceManager::class => RemoteAppProdSourceManager::class,
        AppProdUserManager::class => RemoteAppProdUserManager::class,
        AppStateInspector::class => NativeAppStateInspector::class,
        FirewallInspector::class => NativeUfwFirewallInspector::class,
        FirewallManager::class => NativeUfwFirewallManager::class,
        GatewayVpnStateInspector::class => NativeGatewayVpnStateInspector::class,
        HostKeyScanner::class => SshHostKeyScanner::class,
        InstanceStateInspector::class => NativeInstanceStateInspector::class,
        MetricsCredentialManager::class => NativeMetricsCredentialManager::class,
        MetricsCredentialRuntime::class => MetricsSshExecutor::class,
        MetricsExporterLifecycle::class => NativeMetricsExporterLifecycle::class,
        MetricsExporterProjection::class => NativeMetricsExporterProjection::class,
        MetricsFirewallExpectationProvider::class => NativeMetricsFirewallExpectationProvider::class,
        MetricsExporterRuntime::class => MetricsExporterSshExecutor::class,
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
        ProcessStateInspector::class => NativeProcessStateInspector::class,
        NodeRoleDependencyInspector::class => EloquentNodeRoleDependencyInspector::class,
        NodeRoleDependentCleaner::class => NativeNodeRoleDependentCleaner::class,
        NodeRoleToolIntentGuard::class => EloquentNodeRoleToolIntentGuard::class,
        NodeRoleFirewallManager::class => NativeNodeRoleFirewallManager::class,
        RoleBaselineConverger::class => NativeRoleBaselineConverger::class,
        ProcessRuntimeManager::class => RemoteProcessRuntimeManager::class,
        RepositoryDefaultBranchResolver::class => NativeRepositoryDefaultBranchResolver::class,
        ProcessRunner::class => NativeProcessRunner::class,
        SshExecutor::class => NativeSshExecutor::class,
        PrivateDnsManager::class => DnsmasqPrivateDnsManager::class,
        RoleStateInspector::class => NativeRoleStateInspector::class,
        ToolInspector::class => NativeToolInspector::class,
        ToolManagerMaterializer::class => NativeToolManagerMaterializer::class,
        ToolOperationLock::class => NativeToolOperationLock::class,
        WorkspaceStateInspector::class => NativeWorkspaceStateInspector::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ManagedUserAccountResolver::class, SshManagedUserAccountResolver::class);
        $this->app->scoped(NodeProvisioningLock::class, NativeNodeProvisioningLock::class);
        $this->app->scoped(ToolManagerScopeLock::class, NativeToolManagerScopeLock::class);
        // Shared for one request so the Metrics baseline's removal outcome
        // reaches the disable response instead of being inferred a second time.
        $this->app->scoped(MetricsPublicationReport::class);

        if (class_exists(GuidelineComposer::class)) {
            $this->app->singleton(GuidelineComposer::class, GatewayGuidelineComposer::class);
            $this->app->singleton(InstallCommand::class, GatewayBoostInstallCommand::class);
        }

        $this->app->singleton(CommandDeadline::class);
        $this->app->singleton(
            ToolManagerRegistry::class,
            static fn (): ToolManagerRegistry => new ToolManagerRegistry([
                app(AptToolManager::class),
                app(VpToolManager::class),
                app(ComposerToolManager::class),
            ]),
        );
        $this->app->singleton(
            AppDevSourceOperationLock::class,
            static fn (): AppDevSourceOperationLock => new NativeAppDevSourceOperationLock(
                rtrim(string: (string) config('orbit.home'), characters: '/').'/locks/app-dev-source',
            ),
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
                caddyRenderer: app(GatewayCaddyConfigRenderer::class),
                fpmRenderer: app(GatewayFpmConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                checkout: new GatewayCheckoutAccessConverger(
                    processes: app(ProcessRunner::class),
                    checkoutPath: rtrim(string: (string) config('orbit.gateway_checkout'), characters: '/'),
                ),
                certificatePublisher: new NativeGatewayCertificatePublisher(
                    processes: app(ProcessRunner::class),
                    orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
                ),
                fpm: new NativeGatewayFpmConverger(app(ProcessRunner::class)),
                caddy: new NativeGatewayCaddyConverger(app(ProcessRunner::class)),
                orbitHome: rtrim(string: (string) config('orbit.home'), characters: '/'),
                checkoutPath: rtrim(string: (string) config('orbit.gateway_checkout'), characters: '/'),
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
    }

    public function boot(ActivityPropertiesObserver $activityPropertiesObserver): void
    {
        Activity::observe($activityPropertiesObserver);
    }

    private static function resolveManagedUserHomeDirectory(string $user): string|false
    {
        $account = posix_getpwnam($user);

        return is_array($account) ? (string) $account['dir'] : false;
    }
}
