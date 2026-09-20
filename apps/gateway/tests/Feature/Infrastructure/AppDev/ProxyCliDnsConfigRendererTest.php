<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\Node;

it('publishes proxycli.orbit on the collector Node, not the Gateway', function (): void {
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
        ->toContain('host-record=proxycli.orbit,10.44.0.8')
        ->not
        ->toContain('host-record=proxycli.orbit,10.44.0.1'.PHP_EOL);
});

it('omits proxycli.orbit when the fleet feature is disabled', function (): void {
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

    expect($configuration)->not->toContain('proxycli.orbit');
});
