<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\Setting;

it('generates credentials once and keeps them stable across converges', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);

    $first = $manager->ensure($node);
    $second = $manager->ensure($node);

    expect($second->appId)->toBe($first->appId)
        ->and($second->appKey)->toBe($first->appKey)
        ->and($second->appSecret)->toBe($first->appSecret)
        ->and($second->laravelAppKey)->toBe($first->laravelAppKey)
        ->and($first->appId)->not->toBe('')
        ->and($first->laravelAppKey)->toStartWith('base64:');
});

it('never stores the secret or the generated APP_KEY unencrypted', function (): void {
    $node = websocketCredentialsNode();
    $credentials = app(WebSocketCredentialManager::class)->ensure($node);

    $rows = Setting::query()->where('scope_type', 'node')->where('scope_id', $node->id)->get();

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        if (in_array($row->key, [
            WebSocketFootprint::SettingKeyAppSecret,
            WebSocketFootprint::SettingKeyAppKeyLaravel,
        ], true)) {
            expect($row->is_secret)->toBeTrue()
                ->and($row->value)->not->toContain($credentials->appSecret)
                ->and($row->value)->not->toContain($credentials->laravelAppKey);
        }
    }
});

it('returns null credentials when no websocket role is active', function (): void {
    expect(app(WebSocketCredentialManager::class)->current())->toBeNull();
});

it('returns null credentials when more than one websocket role is active', function (): void {
    $first = websocketCredentialsNode('websocket-a', '10.44.0.91');
    $second = websocketCredentialsNode('websocket-b', '10.44.0.92');
    app(WebSocketCredentialManager::class)->ensure($first);
    app(WebSocketCredentialManager::class)->ensure($second);

    expect(app(WebSocketCredentialManager::class)->current())->toBeNull();
});

it('reads the active assignment credentials through current()', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);
    $ensured = $manager->ensure($node);

    $current = $manager->current();

    expect($current)->not->toBeNull()
        ->and($current?->appId)->toBe($ensured->appId)
        ->and($current?->appKey)->toBe($ensured->appKey)
        ->and($current?->appSecret)->toBe($ensured->appSecret)
        ->and($current?->laravelAppKey)->toBe($ensured->laravelAppKey);
});

it('purges every stored credential', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);
    $manager->ensure($node);

    $manager->purge($node);

    expect(Setting::query()->where('scope_type', 'node')->where('scope_id', $node->id)->count())->toBe(0);
});

function websocketCredentialsNode(string $name = 'websocket-node', string $wireguardIp = '10.44.0.90'): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);

    return $node->refresh();
}
