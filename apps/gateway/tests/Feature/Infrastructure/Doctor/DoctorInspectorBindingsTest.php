<?php

declare(strict_types=1);

use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Doctor\WorkspaceStateInspector;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Tools\ToolInspector;
use App\Infrastructure\Doctor\NativeAppStateInspector;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Doctor\NativeInstanceStateInspector;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Doctor\NativeRoleStateInspector;
use App\Infrastructure\Doctor\NativeWorkspaceStateInspector;
use App\Infrastructure\Doctor\SshNodeStateInspector;
use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Infrastructure\Tools\NativeToolInspector;

it('resolves every read-only inspector through its domain contract', function (): void {
    expect(app(NodeStateInspector::class))
        ->toBeInstanceOf(SshNodeStateInspector::class)
        ->and(app(RoleStateInspector::class))
        ->toBeInstanceOf(NativeRoleStateInspector::class)
        ->and(app(GatewayVpnStateInspector::class))
        ->toBeInstanceOf(NativeGatewayVpnStateInspector::class)
        ->and(app(AppStateInspector::class))
        ->toBeInstanceOf(NativeAppStateInspector::class)
        ->and(app(InstanceStateInspector::class))
        ->toBeInstanceOf(NativeInstanceStateInspector::class)
        ->and(app(ProcessStateInspector::class))
        ->toBeInstanceOf(NativeProcessStateInspector::class)
        ->and(app(WorkspaceStateInspector::class))
        ->toBeInstanceOf(NativeWorkspaceStateInspector::class)
        ->and(app(ToolInspector::class))
        ->toBeInstanceOf(NativeToolInspector::class)
        ->and(app(FirewallInspector::class))
        ->toBeInstanceOf(NativeUfwFirewallInspector::class)
        ->and(app(MetricsFirewallExpectationProvider::class))
        ->toBeInstanceOf(NativeMetricsFirewallExpectationProvider::class);
});
