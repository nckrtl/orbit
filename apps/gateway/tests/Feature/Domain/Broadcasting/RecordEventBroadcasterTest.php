<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

describe('RecordEventBroadcaster', function (): void {
    it('logs a warning and does not throw when the Reverb connection fails', function (): void {
        config(['broadcasting.default' => 'reverb']);

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

        new RecordEventBroadcaster()->broadcast(RecordEventType::NodeCreated, 9, ['id' => 9, 'name' => 'edge']);
    });

    it('broadcasts without error when the connection is healthy', function (): void {
        config(['broadcasting.default' => 'reverb']);

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

        new RecordEventBroadcaster()->broadcast(RecordEventType::NodeCreated, 9, ['id' => 9, 'name' => 'edge']);

        expect($broadcasted)->toHaveCount(1);
        expect($broadcasted[0][0])->toBe('node.created');
        expect($broadcasted[0][1]['id'])->toBe(9);
    });
});
