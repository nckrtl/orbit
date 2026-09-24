<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\Node;
use App\Models\Route;

it('publishes collector.cli-proxy-api.orbit on the collector Node, not the Gateway or the apex', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $collector = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    app(ProxyCliState::class)->enable(
        $collector->id,
        'valkey',
        'http://127.0.0.1:8317',
        'management-key',
        'read-token',
        'control-token',
    );

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect($configuration)
        ->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.8')
        ->not
        ->toContain('host-record=cli-proxy-api.orbit,')
        ->not
        ->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.1'.PHP_EOL)
        ->not
        ->toContain('collector.proxycli.orbit');
});

it('publishes one collector record while a custom proxy Route still serves the name', function (): void {
    $collector = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.17',
    ]);
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $collector->id,
        'domain' => 'collector.cli-proxy-api.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Active,
    ]);
    $route->customProxy()->create(['node_id' => $collector->id, 'upstream' => 'http://127.0.0.1:8787']);
    app(ProxyCliState::class)->enable($collector->id, 'valkey', 'http://127.0.0.1:8317', 'key', 'read', 'control');

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect(substr_count($configuration, 'host-record=collector.cli-proxy-api.orbit,'))->toBe(1)
        ->and($configuration)->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.17'.PHP_EOL);
});

it('omits collector.cli-proxy-api.orbit when the fleet feature is disabled', function (): void {
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $state = app(ProxyCliState::class);
    $state->enable($node->id, 'valkey', 'http://127.0.0.1:8317', 'key', 'read', 'control');
    $state->disable();

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect($configuration)
        ->not
        ->toContain('collector.cli-proxy-api.orbit')
        ->not
        ->toContain('host-record=cli-proxy-api.orbit,');
});
