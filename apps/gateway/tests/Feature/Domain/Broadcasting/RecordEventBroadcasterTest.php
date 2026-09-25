<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

describe('RecordEventBroadcaster', function (): void {
    it('does nothing, and never touches Reverb, without an active websocket role', function (): void {
        Log::shouldReceive('warning')->never();

        app(RecordEventBroadcaster::class)
            ->broadcast(RecordEventType::NodeCreated, 9, ['id' => 9, 'name' => 'edge']);

        expect(config('broadcasting.default'))->toBe('null');
    });

    it('logs a warning and does not throw when the Reverb connection fails', function (): void {
        activate_websocket_role();

        Broadcast::extend('reverb', function (): Broadcaster {
            return new class implements Broadcaster
            {
                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    throw new RuntimeException('Reverb is unreachable.');
                }
            };
        });

        Log::shouldReceive('warning')
            ->once()
            ->with('Failed to broadcast a record event.', Mockery::on(function (array $context): bool {
                expect($context['type'])->toBe('node.created');
                expect($context['id'])->toBe(9);
                expect($context['exception'])->toBe('Reverb is unreachable.');

                return true;
            }));

        app(RecordEventBroadcaster::class)
            ->broadcast(RecordEventType::NodeCreated, 9, ['id' => 9, 'name' => 'edge']);
    });

    it('broadcasts without error when the websocket role is active and Reverb is healthy', function (): void {
        [, $credentials] = activate_websocket_role();

        $broadcasted = [];

        Broadcast::extend('reverb', function () use (&$broadcasted): Broadcaster {
            return new class($broadcasted) implements Broadcaster
            {
                public function __construct(private array &$broadcasted) {}

                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $this->broadcasted[] = [$event, $payload];
                }
            };
        });

        Log::shouldReceive('warning')->never();

        app(RecordEventBroadcaster::class)
            ->broadcast(RecordEventType::NodeCreated, 9, ['id' => 9, 'name' => 'edge']);

        expect($broadcasted)->toHaveCount(1);
        expect($broadcasted[0][0])->toBe('node.created');
        expect($broadcasted[0][1]['id'])->toBe(9);
        expect(config('broadcasting.connections.reverb.key'))->toBe($credentials->appKey);
        expect(config('broadcasting.connections.reverb.options.host'))->toBe('reverb.orbit');
    });

    it('sends each event to both Reverb servers during a websocket move, and keeps going when one fails', function (): void {
        $source = Node::query()->create([
            'name' => 'websocket-source',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.89',
            'wireguard_ip' => '10.44.0.89',
            'user' => 'orbit',
        ]);
        new CaddySiteCertificates()->record($source->id, CaddySiteCertificates::Websocket);
        [$target] = activate_websocket_role();
        new CaddySiteCertificates()->record($target->id, CaddySiteCertificates::Websocket);
        new WebSocketDnsTarget()->markServing($target->id);
        $sent = [];

        Broadcast::extend('reverb', function () use (&$sent): Broadcaster {
            return new class($sent) implements Broadcaster
            {
                public function __construct(private array &$sent) {}

                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $resolve = config('broadcasting.connections.reverb.client_options.curl')[CURLOPT_RESOLVE][0] ?? '';

                    if (str_ends_with($resolve, ':10.44.0.90')) {
                        throw new RuntimeException('The new Reverb is unreachable.');
                    }

                    $this->sent[] = [$resolve, $event, $payload['id']];
                }
            };
        });

        Log::shouldReceive('warning')->once();

        app(RecordEventBroadcaster::class)
            ->broadcast(RecordEventType::NodeCreated, 9, ['id' => 9, 'name' => 'edge']);

        expect($sent)->toBe([['reverb.orbit:443:10.44.0.89', 'node.created', 9]]);
    });

    it('gives the old server of a move a short limit and skips it after a failure, never slowing the serving server', function (): void {
        config()->set('orbit.home', sys_get_temp_dir().'/orbit-broadcast-'.bin2hex(random_bytes(4)));
        $source = Node::query()->create([
            'name' => 'websocket-source',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.89',
            'wireguard_ip' => '10.44.0.89',
            'user' => 'orbit',
        ]);
        new CaddySiteCertificates()->record($source->id, CaddySiteCertificates::Websocket);
        [$target] = activate_websocket_role();
        new CaddySiteCertificates()->record($target->id, CaddySiteCertificates::Websocket);
        new WebSocketDnsTarget()->markServing($target->id);
        $sent = [];
        $oldReachable = false;

        Broadcast::extend('reverb', function () use (&$sent, &$oldReachable): Broadcaster {
            return new class($sent, $oldReachable) implements Broadcaster
            {
                public function __construct(private array &$sent, private bool &$oldReachable) {}

                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $address = substr(config('broadcasting.connections.reverb.client_options.curl')[CURLOPT_RESOLVE][0] ?? '', strlen('reverb.orbit:443:'));
                    $this->sent[] = [
                        $address,
                        config('broadcasting.connections.reverb.client_options.connect_timeout'),
                        config('broadcasting.connections.reverb.client_options.timeout'),
                    ];

                    if ($address === '10.44.0.89' && ! $this->oldReachable) {
                        throw new RuntimeException('cURL error 28: Connection timed out after 300 milliseconds');
                    }
                }
            };
        });
        Log::shouldReceive('warning')->once();
        $broadcast = static function (): void {
            app()->forgetScopedInstances();
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, 9, ['id' => 9]);
        };

        try {
            $broadcast();
            $broadcast();

            expect($sent)->toBe([
                ['10.44.0.90', 2, 5],
                ['10.44.0.89', 0.3, 0.5],
                ['10.44.0.90', 2, 5],
            ]);

            // The skip window has passed: the old server's failure marker is older than the retry limit.
            touch(config('orbit.home').'/cache/reverb-old-server/'.hash('sha256', '10.44.0.89'), time() - RealtimeConnection::OldServerRetrySeconds - 1);
            $oldReachable = true;
            $sent = [];
            $broadcast();

            expect($sent)->toBe([
                ['10.44.0.90', 2, 5],
                ['10.44.0.89', 0.3, 0.5],
            ]);
        } finally {
            new Filesystem()->deleteDirectory((string) config('orbit.home'));
        }
    });
});
