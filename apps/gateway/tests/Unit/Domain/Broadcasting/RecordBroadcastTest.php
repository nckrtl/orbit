<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

describe('RecordBroadcast envelope', function (): void {
    it('broadcasts synchronously on the private orbit channel named after the event type', function (): void {
        $event = new RecordBroadcast(RecordEventType::NodeCreated, 7, ['id' => 7, 'name' => 'app-dev']);

        expect($event)->toBeInstanceOf(ShouldBroadcastNow::class);
        expect($event->broadcastAs())->toBe('node.created');

        $channels = $event->broadcastOn();
        expect($channels)->toHaveCount(1);
        expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
        expect($channels[0]->name)->toBe('private-orbit');
    });

    it('carries the fixed type, id, at, and data envelope', function (): void {
        $data = ['id' => 42, 'name' => 'web'];
        $event = new RecordBroadcast(RecordEventType::ProcessStatus, 42, $data);

        $payload = $event->broadcastWith();

        expect(array_keys($payload))->toBe(['type', 'id', 'at', 'data']);
        expect($payload['type'])->toBe('process.status');
        expect($payload['id'])->toBe(42);
        expect($payload['data'])->toBe($data);
        expect($payload['at'])->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/');
    });

    it('accepts a string id for a record without a numeric identity', function (): void {
        $event = new RecordBroadcast(RecordEventType::DeployStepCreated, 'migrate', ['name' => 'migrate']);

        expect($event->broadcastWith()['id'])->toBe('migrate');
    });
});
