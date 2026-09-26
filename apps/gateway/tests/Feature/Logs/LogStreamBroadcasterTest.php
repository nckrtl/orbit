<?php

declare(strict_types=1);

use App\Domain\Logs\LogStreamBroadcaster;
use App\Domain\Logs\LogStreamEndReason;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

describe('LogStreamBroadcaster during a websocket move', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.home', sys_get_temp_dir().'/orbit-log-broadcast-'.bin2hex(random_bytes(4)));
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

        $this->sent = [];
        $this->unreachable = [];
        $sent = &$this->sent;
        $unreachable = &$this->unreachable;

        Broadcast::extend('reverb', function () use (&$sent, &$unreachable): Broadcaster {
            return new class($sent, $unreachable) implements Broadcaster
            {
                public function __construct(private array &$sent, private array &$unreachable) {}

                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $address = substr(config('broadcasting.connections.reverb.client_options.curl')[CURLOPT_RESOLVE][0] ?? '', strlen('reverb.orbit:443:'));

                    if (in_array($address, $this->unreachable, true)) {
                        throw new RuntimeException("Reverb at {$address} is unreachable.");
                    }

                    $this->sent[] = [$address, (string) $channels[0], $event];
                }
            };
        });
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory((string) config('orbit.home'));
    });

    it('sends lines, ends, and prompts to the serving and the old Reverb server', function (): void {
        $broadcaster = app(LogStreamBroadcaster::class);
        $stream = str_repeat('a', 32);

        $broadcaster->lines($stream, 1, ['one'], 0, 0);
        $broadcaster->ended($stream, LogStreamEndReason::Closed);
        $broadcaster->changed(7);

        expect($this->sent)->toBe([
            ['10.44.0.90', "private-log-stream.{$stream}", 'log.lines'],
            ['10.44.0.89', "private-log-stream.{$stream}", 'log.lines'],
            ['10.44.0.90', "private-log-stream.{$stream}", 'log.ended'],
            ['10.44.0.89', "private-log-stream.{$stream}", 'log.ended'],
            ['10.44.0.90', 'presence-node-logs.7', 'log-streams.changed'],
            ['10.44.0.89', 'presence-node-logs.7', 'log-streams.changed'],
        ]);
    });

    it('relays lines to the serving server when the old server fails', function (): void {
        $this->unreachable = ['10.44.0.89'];
        Log::shouldReceive('warning')->once();

        app(LogStreamBroadcaster::class)->lines(str_repeat('b', 32), 1, ['one'], 0, 0);

        expect(array_column($this->sent, 0))->toBe(['10.44.0.90']);
    });

    it('fails the relay run when the serving server fails, after the old server had its try', function (): void {
        $this->unreachable = ['10.44.0.90'];

        expect(fn () => app(LogStreamBroadcaster::class)->lines(str_repeat('c', 32), 1, ['one'], 0, 0))
            ->toThrow(RuntimeException::class, 'Reverb at 10.44.0.90 is unreachable.')
            ->and(array_column($this->sent, 0))->toBe(['10.44.0.89']);
    });
});
