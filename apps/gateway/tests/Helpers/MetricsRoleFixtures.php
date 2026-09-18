<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

/**
 * A Node carrying the active Metrics role, which is what makes the Gateway able to resolve where
 * Grafana is. A test that fakes Grafana's HTTP responses still needs this, because the address is
 * read from the assignment rather than configured.
 */
function activate_metrics_role(?Node $node = null): Node
{
    $node ??= Node::query()->create([
        'name' => 'metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
        'user' => 'orbit',
    ]);

    $node->roles()->updateOrCreate(
        ['role' => RoleName::Metrics],
        ['status' => LifecycleStatus::Active],
    );

    return $node->refresh();
}
