<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Models\Node;

it('projects selected exporter and Gateway-only publication expectations in catalog order', function (): void {
    $metrics = metricsFirewallExpectationNode('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $gateway = metricsFirewallExpectationNode('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $app = metricsFirewallExpectationNode('app', '10.44.0.4');
    $app->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $excluded = metricsFirewallExpectationNode('excluded', '10.44.0.5');
    $excluded->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $preferences = app(ExporterPreferenceRepository::class);
    $preferences->put($excluded->id, ExporterPreference::Disabled);
    $provider = new NativeMetricsFirewallExpectationProvider(
        new NativeMetricsExporterProjection(new ExporterSelector, $preferences),
        new MetricsGatewayResolver,
        new NodeFirewallRuleCatalog,
    );

    expect(array_column($provider->for($metrics), 'resourceId'))
        ->toBe(['orbit:metrics-node-exporter', 'orbit:metrics-grafana-upstream'])
        ->and(array_column($provider->for($gateway), 'resourceId'))
        ->toBe(['orbit:metrics-node-exporter'])
        ->and(array_column($provider->for($app), 'resourceId'))
        ->toBe(['orbit:metrics-node-exporter'])
        ->and($provider->for($excluded))
        ->toBe([]);

    $gateway->roles()->update(['status' => 'failed']);

    expect(array_column($provider->for($metrics), 'resourceId'))
        ->toBe(['orbit:metrics-node-exporter']);
});

it('returns no expectations for absent or ambiguous active Metrics assignment state', function (): void {
    $node = metricsFirewallExpectationNode('node', '10.44.0.2');
    $provider = new NativeMetricsFirewallExpectationProvider(
        new NativeMetricsExporterProjection(
            new ExporterSelector,
            app(ExporterPreferenceRepository::class),
        ),
        new MetricsGatewayResolver,
        new NodeFirewallRuleCatalog,
    );

    expect($provider->for($node))->toBe([]);

    foreach (['metrics-one', 'metrics-two'] as $index => $name) {
        $metrics = metricsFirewallExpectationNode($name, "10.44.0.{$index}5");
        $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    }

    expect($provider->for($node))->toBe([]);
});

function metricsFirewallExpectationNode(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '127.0.0.1',
        'ssh_user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
}
