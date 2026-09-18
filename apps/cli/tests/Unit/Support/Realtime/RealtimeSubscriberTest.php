<?php

declare(strict_types=1);

use App\Support\Realtime\FakeRealtimeChannelAuthorizer;
use App\Support\Realtime\FakeWebSocketClose;
use App\Support\Realtime\FakeWebSocketTransport;
use App\Support\Realtime\RealtimeConnectionException;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;

describe(RealtimeSubscriber::class, function (): void {
    it('reports not_configured and never touches the transport when no realtime endpoint is set', function (): void {
        $transport = new FakeWebSocketTransport;
        $subscriber = new RealtimeSubscriber($transport, null, new FakeRealtimeChannelAuthorizer);

        expect($subscriber->state())->toBe(RealtimeState::NotConfigured);

        $subscriber->connect();
        $events = $subscriber->poll();

        expect($events)->toBe([])
            ->and($subscriber->state())->toBe(RealtimeState::NotConfigured)
            ->and($transport->connections)->toBe([]);
    });

    it('performs the connect, socket_id, authorize, and subscribe sequence against a fixture transport', function (): void {
        $transport = realtime_fixture_transport('handshake');
        $authorizer = new FakeRealtimeChannelAuthorizer('app-key:deadbeef');
        $subscriber = new RealtimeSubscriber($transport, realtime_test_connection_config(), $authorizer);

        expect($subscriber->state())->toBe(RealtimeState::Reconnecting);

        $subscriber->connect();
        $events = $subscriber->poll();

        expect($events)->toBe([])
            ->and($subscriber->state())->toBe(RealtimeState::Connected)
            ->and($transport->connections)->toBe([
                ['url' => 'wss://reverb.test/app/app-key?protocol=7&client=orbit-cli&version=1.0.0', 'ca_path' => null],
            ])
            ->and($authorizer->calls)->toBe([
                ['socket_id' => '123.456', 'channel_name' => 'private-orbit'],
            ])
            ->and($transport->sent)->toBe([
                ['event' => 'pusher:subscribe', 'data' => ['auth' => 'app-key:deadbeef', 'channel' => 'private-orbit']],
            ]);
    });

    it('decodes a full mixed event fixture into ordered events and answers the protocol ping', function (): void {
        $transport = realtime_fixture_transport('mixed_events');
        $subscriber = new RealtimeSubscriber($transport, realtime_test_connection_config(), new FakeRealtimeChannelAuthorizer);

        $subscriber->connect();
        $events = $subscriber->poll();

        expect($events)->toHaveCount(4);
        expect(array_map(fn ($event) => $event->type, $events))->toBe([
            'node.created',
            'process.status',
            'schedule.updated',
            'deployment.log',
        ]);
        expect(array_map(fn ($event) => $event->id, $events))->toBe([101, 102, 103, 104]);
        expect($events[0]->data)->toBe(['id' => 7, 'name' => 'beast', 'status' => 'provisioning']);
        expect($events[3]->data)->toBe(['line' => 'Composer install complete']);

        // The Pusher-level ping was answered with a pong and never surfaced as an event.
        expect($transport->sent)->toHaveCount(2);
        expect($transport->sent[0]['event'])->toBe('pusher:subscribe');
        expect($transport->sent[1]['event'])->toBe('pusher:pong');
    });

    it('drains events across multiple polls without blocking, preserving arrival order', function (): void {
        $transport = new FakeWebSocketTransport([
            ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => 's1'])],
            ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => '{}'],
            null,
            ['event' => 'node.created', 'channel' => 'private-orbit', 'data' => json_encode([
                'type' => 'node.created', 'id' => 1, 'at' => '2026-09-18T10:00:00+00:00', 'data' => [],
            ])],
            null,
            null,
            ['event' => 'node.created', 'channel' => 'private-orbit', 'data' => json_encode([
                'type' => 'node.created', 'id' => 2, 'at' => '2026-09-18T10:00:01+00:00', 'data' => [],
            ])],
        ]);
        $subscriber = new RealtimeSubscriber($transport, realtime_test_connection_config(), new FakeRealtimeChannelAuthorizer);

        $subscriber->connect();

        expect($subscriber->poll())->toBe([]);
        expect($subscriber->state())->toBe(RealtimeState::Connected);

        $first = $subscriber->poll();
        expect($first)->toHaveCount(1)->and($first[0]->id)->toBe(1);

        expect($subscriber->poll())->toBe([]);

        $second = $subscriber->poll();
        expect($second)->toHaveCount(1)->and($second[0]->id)->toBe(2);
    });

    it('reconnects with backoff after the peer sends a close frame', function (): void {
        $time = 1_000.0;
        $clock = static function () use (&$time): float {
            return $time;
        };

        $transport = new FakeWebSocketTransport([
            ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => 's1'])],
            ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => '{}'],
            new FakeWebSocketClose,
        ]);
        $subscriber = new RealtimeSubscriber($transport, realtime_test_connection_config(), new FakeRealtimeChannelAuthorizer, $clock);

        $subscriber->connect();

        expect($subscriber->poll())->toBe([])
            ->and($subscriber->state())->toBe(RealtimeState::Reconnecting)
            ->and($transport->connections)->toHaveCount(1);

        // The backoff has not elapsed yet: no new connection attempt.
        expect($subscriber->poll())->toBe([])
            ->and($transport->connections)->toHaveCount(1);

        $transport->enqueue([
            ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => 's2'])],
            ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => '{}'],
        ]);
        $time += 1.5;

        expect($subscriber->poll())->toBe([])
            ->and($subscriber->state())->toBe(RealtimeState::Connected)
            ->and($transport->connections)->toHaveCount(2);
    });

    it('reconnects after the socket throws mid-stream', function (): void {
        $transport = new FakeWebSocketTransport([
            ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => 's1'])],
            ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-orbit', 'data' => '{}'],
            new RealtimeConnectionException('dropped'),
        ]);
        $time = 0.0;
        $subscriber = new RealtimeSubscriber(
            $transport,
            realtime_test_connection_config(),
            new FakeRealtimeChannelAuthorizer,
            static function () use (&$time): float {
                return $time;
            },
        );

        $subscriber->connect();

        expect($subscriber->poll())->toBe([])
            ->and($subscriber->state())->toBe(RealtimeState::Reconnecting);
    });
});
