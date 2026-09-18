<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Node;

/**
 * Activates the `websocket` role on a Node and generates its Reverb
 * credentials through the real credential manager, so realtime tests
 * exercise the same resolution path production code does instead of
 * poking `broadcasting.*` config directly.
 *
 * @return array{Node, WebSocketCredentials}
 */
function activate_websocket_role(?Node $node = null): array
{
    $node ??= Node::query()->create([
        'name' => 'websocket-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => '10.44.0.90',
        'user' => 'orbit',
    ]);

    $node->roles()->updateOrCreate(
        ['role' => RoleName::WebSocket],
        ['status' => LifecycleStatus::Active],
    );

    $credentials = app(WebSocketCredentialManager::class)->ensure($node->refresh());

    return [$node, $credentials];
}
