<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Models\Node;

it('resolves null without an active websocket role', function (): void {
    expect(app(RealtimeConnection::class)->resolve())->toBeNull();
    expect(app(RealtimeConnection::class)->configureBroadcasting())->toBeFalse();
});

it('resolves the connection from the active websocket role assignment', function (): void {
    [, $credentials] = activate_websocket_role();

    $connection = app(RealtimeConnection::class)->resolve();

    expect($connection)->not->toBeNull()
        ->and($connection?->host)->toBe('reverb.orbit')
        ->and($connection?->port)->toBe(443)
        ->and($connection?->scheme)->toBe('https')
        ->and($connection?->appId)->toBe($credentials->appId)
        ->and($connection?->key)->toBe($credentials->appKey)
        ->and($connection?->secret)->toBe($credentials->appSecret)
        ->and($connection?->url())->toBe('wss://reverb.orbit')
        ->and($connection?->caCertificatePath)->toEndWith('/ca/root.pem');
});

it('memoizes the resolution for the life of the instance', function (): void {
    $calls = 0;
    $node = activate_websocket_role()[0];

    $realtime = new RealtimeConnection(
        credentials: new class($calls) implements WebSocketCredentialManager
        {
            public function __construct(private int &$calls) {}

            public function ensure(Node $node): WebSocketCredentials
            {
                throw new LogicException('not used');
            }

            public function current(): ?WebSocketCredentials
            {
                $this->calls++;

                return new WebSocketCredentials('id', 'key', 'secret', 'base64:'.base64_encode('k'));
            }

            public function purge(Node $node): void {}
        },
        orbitHome: '/tmp/orbit-home',
    );

    $realtime->resolve();
    $realtime->resolve();
    $realtime->configureBroadcasting();

    expect($calls)->toBe(1);
});

it('configures the reverb broadcaster and the Orbit CA verification path', function (): void {
    activate_websocket_role();

    $configured = app(RealtimeConnection::class)->configureBroadcasting();

    expect($configured)->toBeTrue()
        ->and(config('broadcasting.default'))->toBe('reverb')
        ->and(config('broadcasting.connections.reverb.options.host'))->toBe('reverb.orbit')
        ->and(config('broadcasting.connections.reverb.options.useTLS'))->toBeTrue()
        ->and(config('broadcasting.connections.reverb.client_options.verify'))->toEndWith('/ca/root.pem');
});

it('connects to the serving node by address, because the Gateway host cannot resolve reverb.orbit', function (): void {
    activate_websocket_role();

    app(RealtimeConnection::class)->configureBroadcasting();

    expect(config('broadcasting.connections.reverb.client_options.curl'))
        ->toBe([CURLOPT_RESOLVE => ['reverb.orbit:443:10.44.0.90']]);
});

it('reads the websocket role again after it forgets the connection', function (): void {
    $realtime = app(RealtimeConnection::class);
    expect($realtime->resolve())->toBeNull();

    [, $credentials] = activate_websocket_role();
    expect($realtime->resolve())->toBeNull();

    $realtime->forget();

    expect($realtime->resolve()?->key)->toBe($credentials->appKey);
});

it('gives the agent view subscriber a broadcast refresh that forgets the resolved connection', function (): void {
    $realtime = app(RealtimeConnection::class);
    expect($realtime->resolve())->toBeNull();
    [, $credentials] = activate_websocket_role();
    $subscriber = app(AgentViewSubscriber::class);

    $refresh = new ReflectionProperty($subscriber, 'broadcastingChanged')->getValue($subscriber);
    expect($refresh)->toBeInstanceOf(Closure::class);
    $refresh();

    expect(app(RealtimeConnection::class)->resolve()?->key)->toBe($credentials->appKey);
});
