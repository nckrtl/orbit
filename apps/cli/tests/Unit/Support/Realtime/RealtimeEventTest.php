<?php

declare(strict_types=1);

use App\Support\Realtime\RealtimeEvent;
use App\Support\Realtime\RealtimeProtocolException;

describe(RealtimeEvent::class, function (): void {
    it('decodes a JSON-string channel payload into its envelope', function (): void {
        $event = RealtimeEvent::fromChannelPayload(
            'node.created',
            '{"type":"node.created","id":101,"at":"2026-09-18T10:00:00+00:00","data":{"id":7,"name":"beast"}}',
        );

        expect($event->type)->toBe('node.created')
            ->and($event->id)->toBe(101)
            ->and($event->at->format(DATE_ATOM))->toBe('2026-09-18T10:00:00+00:00')
            ->and($event->data)->toBe(['id' => 7, 'name' => 'beast']);
    });

    it('decodes an already-decoded array channel payload', function (): void {
        $event = RealtimeEvent::fromChannelPayload('process.status', [
            'type' => 'process.status',
            'id' => 42,
            'at' => '2026-09-18T10:00:05+00:00',
            'data' => ['status' => 'running'],
        ]);

        expect($event->type)->toBe('process.status')
            ->and($event->data)->toBe(['status' => 'running']);
    });

    it('round trips through toArray()', function (): void {
        $event = RealtimeEvent::fromChannelPayload('node.created', [
            'type' => 'node.created',
            'id' => 101,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 7],
        ]);

        expect($event->toArray())->toBe([
            'type' => 'node.created',
            'id' => 101,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 7],
        ]);
    });

    it('rejects a payload that is not valid JSON', function (): void {
        RealtimeEvent::fromChannelPayload('node.created', '{not json');
    })->throws(RealtimeProtocolException::class, 'not valid JSON');

    it('rejects a payload that decodes to a scalar instead of an object', function (): void {
        RealtimeEvent::fromChannelPayload('node.created', '"just-a-string"');
    })->throws(RealtimeProtocolException::class, 'malformed');

    it('rejects a payload missing its type', function (): void {
        RealtimeEvent::fromChannelPayload('node.created', ['id' => 1, 'at' => '2026-09-18T10:00:00+00:00', 'data' => []]);
    })->throws(RealtimeProtocolException::class, 'missing its type');

    it('rejects a payload with a non-numeric id', function (): void {
        RealtimeEvent::fromChannelPayload('node.created', [
            'type' => 'node.created', 'id' => 'abc', 'at' => '2026-09-18T10:00:00+00:00', 'data' => [],
        ]);
    })->throws(RealtimeProtocolException::class, 'numeric id');

    it('rejects a payload with an invalid timestamp', function (): void {
        RealtimeEvent::fromChannelPayload('node.created', [
            'type' => 'node.created', 'id' => 1, 'at' => 'not-a-date', 'data' => [],
        ]);
    })->throws(RealtimeProtocolException::class, 'invalid timestamp');

    it('rejects a payload missing its data record', function (): void {
        RealtimeEvent::fromChannelPayload('node.created', [
            'type' => 'node.created', 'id' => 1, 'at' => '2026-09-18T10:00:00+00:00',
        ]);
    })->throws(RealtimeProtocolException::class, 'missing its data record');
});
