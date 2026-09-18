<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use Illuminate\Contracts\Broadcasting\Broadcaster;
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
});
