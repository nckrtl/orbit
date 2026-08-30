<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Models\Node;

it('fails closed before removal when Metrics assignments drift', function (): void {
    foreach (['metrics-a', 'metrics-b'] as $name) {
        $node = Node::query()->create([
            'name' => $name,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => '192.0.2.'.(Node::query()->count() + 20),
            'wireguard_address' => '10.44.0.'.(Node::query()->count() + 20),
        ]);
        $node->roles()->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    }

    expect(fn () => app(NativeMetricsRoleManager::class)->remove(true, false))
        ->toThrow(RoleAssignmentException::class, 'Metrics role assignment drift detected.');
});
