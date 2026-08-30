<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\Node;

it('renders managed header and terminal newline', function (): void {
    $result = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();
    expect($result)->toStartWith('# Managed by Orbit.')->toEndWith("\n");
});

it('projects a provisioning Metrics assignment to the active Gateway address', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.1',
    ]);
    $gateway
        ->roles()
        ->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
    $metrics = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.3',
    ]);
    $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Provisioning,
        ]);

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render($metrics);

    expect($configuration)->toContain('host-record=metrics.orbit,10.44.0.1');
});
