<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\File;
use Tests\Support\LocalShellSshExecutor;

beforeEach(function (): void {
    $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    public_edge_inspector_node('edge-router', '10.44.0.20', '10.10.0.20', $cluster, RoleName::Router);
    $this->ingress = public_edge_inspector_node('edge-ingress', '10.44.0.30', '10.10.0.30', $cluster, RoleName::Ingress);
    $this->route = Route::query()->create([
        'kind' => RouteKind::AnalyticsTracking,
        'cluster_id' => $cluster->id,
        'domain' => 'analytics.shop.example.com',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Active,
        'replacement_step' => RouteReplacementStep::PublicActivated,
    ]);
    $this->caddy = sys_get_temp_dir().'/orbit-public-edge-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$this->caddy}/orbit-versions/v1/fragments");
    symlink("{$this->caddy}/orbit-versions/v1/Caddyfile", "{$this->caddy}/Caddyfile");
});

afterEach(function (): void {
    File::deleteDirectory($this->caddy);
});

it('accepts a public site that opts into certificate automation under the Node-wide default', function (): void {
    public_edge_inspector_publish($this->caddy, CaddyGlobalOptions::render(), public_edge_inspector_site($this->route));

    expect(public_edge_inspector($this->caddy)->inspect($this->ingress, $this->route)->publicTlsMatches)->toBeTrue();
});

it('reports a public site that Caddy will not certify because the Node disables certificate management', function (): void {
    $site = str_replace("    tls force_automate\n", '', public_edge_inspector_site($this->route));
    public_edge_inspector_publish($this->caddy, CaddyGlobalOptions::render(), $site);

    $observation = public_edge_inspector($this->caddy)->inspect($this->ingress, $this->route);

    expect($observation->publicTlsMatches)->toBeFalse()
        ->and($observation->ingressProjectionMatches)->toBeTrue();
});

it('accepts a public site without the option on a Node that still leaves certificate management on', function (): void {
    $site = str_replace("    tls force_automate\n", '', public_edge_inspector_site($this->route));
    public_edge_inspector_publish($this->caddy, '', $site);

    expect(public_edge_inspector($this->caddy)->inspect($this->ingress, $this->route)->publicTlsMatches)->toBeTrue();
});

it('reports a public site that pins an Orbit CA leaf', function (): void {
    $id = $this->route->id;
    $site = str_replace(
        "    tls force_automate\n",
        "    tls /etc/caddy/orbit-certificates/route-{$id}-ingress/current/cert.pem /etc/caddy/orbit-certificates/route-{$id}-ingress/current/key.pem\n",
        public_edge_inspector_site($this->route),
    );
    public_edge_inspector_publish($this->caddy, CaddyGlobalOptions::render(), $site);

    expect(public_edge_inspector($this->caddy)->inspect($this->ingress, $this->route)->publicTlsMatches)->toBeFalse();
});

it('reports a Node that does not serve the public site at all', function (): void {
    public_edge_inspector_publish($this->caddy, CaddyGlobalOptions::render(), '');

    $observation = public_edge_inspector($this->caddy)->inspect($this->ingress, $this->route);

    expect($observation->publicTlsMatches)->toBeFalse()
        ->and($observation->ingressProjectionMatches)->toBeFalse();
});

function public_edge_inspector(string $caddy): NativePublicRouteEdgeInspector
{
    return new NativePublicRouteEdgeInspector(
        new AppDevSshExecutor(
            new LocalShellSshExecutor,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/public-edge-test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 test';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/public-edge-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ),
        new CommandDeadline,
        liveCaddyfilePath: "{$caddy}/Caddyfile",
    );
}

function public_edge_inspector_site(Route $route): string
{
    return new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 1,
            nodeAddress: '10.44.0.30',
            scope: "route-{$route->id}-ingress",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            upstreamAddresses: ['10.10.0.20'],
            certificateScope: "route-{$route->id}-ingress",
            publicListener: true,
            preserveForwardedIdentity: true,
        ),
    ]));
}

function public_edge_inspector_publish(string $caddy, string $globalOptions, string $site): void
{
    file_put_contents(
        "{$caddy}/orbit-versions/v1/Caddyfile",
        $globalOptions."import {$caddy}/orbit-versions/v1/fragments/*.caddy\n",
    );
    file_put_contents("{$caddy}/orbit-versions/v1/fragments/app-dev.caddy", $site);
}

function public_edge_inspector_node(string $name, string $wireguardIp, string $lanIp, Cluster $cluster, RoleName $role): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $wireguardIp,
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'cluster_id' => $cluster->id,
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}
