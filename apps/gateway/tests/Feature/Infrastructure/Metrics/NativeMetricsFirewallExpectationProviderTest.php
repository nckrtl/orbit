<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Models\Node;

it('projects selected exporter and Gateway-only publication expectations in catalog order', function (): void {
    $metrics = metricsFirewallExpectationNode('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $metrics->update(['ssh_host_fingerprint' => null]);
    $gateway = metricsFirewallExpectationNode('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $gateway->update(['ssh_host_fingerprint' => null]);
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
        ->toBe([
            'orbit:metrics-node-exporter',
            'orbit:metrics-grafana-upstream',
            'orbit:metrics-grafana-isolation',
        ])
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

it('returns no exporter expectation for an ineligible record with enabled intent', function (): void {
    $metrics = metricsFirewallExpectationNode('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $client = metricsFirewallExpectationNode('operator-client', '10.44.0.4');
    $client->update(['ssh_host_fingerprint' => null]);
    $preferences = app(ExporterPreferenceRepository::class);
    $preferences->put($client->id, ExporterPreference::Enabled);
    $provider = new NativeMetricsFirewallExpectationProvider(
        new NativeMetricsExporterProjection(new ExporterSelector, $preferences),
        new MetricsGatewayResolver,
        new NodeFirewallRuleCatalog,
    );

    expect($provider->for($client))->toBe([]);
});

it('uses the direct node projection and retains its firewall expectations', function (): void {
    $metrics = metricsFirewallExpectationNode('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $node = metricsFirewallExpectationNode('app', '10.44.0.4');
    $projection = new class implements MetricsExporterProjection
    {
        public int $fleetCalls = 0;

        /** @var list<array{int, int}> */
        public array $nodeCalls = [];

        public function for(Node $metricsNode): array
        {
            $this->fleetCalls++;

            return [];
        }

        public function forNode(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem
        {
            $this->nodeCalls[] = [$metricsNode->id, $node->id];

            return new MetricsExporterProjectionItem(
                $node,
                new ExporterSelector()->select([RoleName::AppProd], eligible: true),
            );
        }
    };
    $provider = new NativeMetricsFirewallExpectationProvider(
        $projection,
        new MetricsGatewayResolver,
        new NodeFirewallRuleCatalog,
    );

    expect(array_column($provider->for($node), 'resourceId'))
        ->toBe(['orbit:metrics-node-exporter'])
        ->and($projection->fleetCalls)
        ->toBe(0)
        ->and($projection->nodeCalls)
        ->toBe([[$metrics->id, $node->id]]);
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
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}
