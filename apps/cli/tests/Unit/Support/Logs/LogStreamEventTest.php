<?php

declare(strict_types=1);

use App\Support\Logs\LogStreamEvent;
use App\Support\Realtime\RealtimeProtocolException;

describe(LogStreamEvent::class, function (): void {
    it('decodes new lines from a JSON payload', function (): void {
        $event = LogStreamEvent::decode('log.lines', json_encode([
            'type' => 'log.lines',
            'id' => str_repeat('a', 32),
            'at' => '2026-09-25T10:15:02+00:00',
            'data' => ['sequence' => 3, 'lines' => ['one', 'two'], 'dropped' => 120, 'skipped' => 0],
        ]));

        expect($event)->not->toBeNull()
            ->and($event?->type)->toBe(LogStreamEvent::LINES)
            ->and($event?->streamId)->toBe(str_repeat('a', 32))
            ->and($event?->sequence)->toBe(3)
            ->and($event?->lines)->toBe(['one', 'two'])
            ->and($event?->dropped)->toBe(120)
            ->and($event?->skipped)->toBe(0);
    });

    it('decodes the end of a stream', function (): void {
        $event = LogStreamEvent::decode('log.ended', [
            'type' => 'log.ended',
            'id' => str_repeat('b', 32),
            'at' => '2026-09-25T10:15:02+00:00',
            'data' => ['reason' => 'agent_left'],
        ]);

        expect($event?->type)->toBe(LogStreamEvent::ENDED)
            ->and($event?->reason)->toBe('agent_left');
    });

    it('ignores client events and other event names', function (string $name): void {
        expect(LogStreamEvent::decode($name, ['stream' => 'x', 'lines' => ['forged']]))->toBeNull();
    })->with(['client-log', 'client-log.lines', 'node.created']);

    it('rejects a log event that breaks its contract', function (string $name, mixed $payload): void {
        LogStreamEvent::decode($name, $payload);
    })->with([
        'invalid JSON' => ['log.lines', '{nope'],
        'type mismatch' => ['log.lines', ['type' => 'log.ended', 'id' => str_repeat('a', 32), 'data' => ['reason' => 'closed']]],
        'numeric id' => ['log.lines', ['type' => 'log.lines', 'id' => 7, 'data' => ['sequence' => 1, 'lines' => [], 'dropped' => 0, 'skipped' => 0]]],
        'zero sequence' => ['log.lines', ['type' => 'log.lines', 'id' => str_repeat('a', 32), 'data' => ['sequence' => 0, 'lines' => [], 'dropped' => 0, 'skipped' => 0]]],
        'line that is not text' => ['log.lines', ['type' => 'log.lines', 'id' => str_repeat('a', 32), 'data' => ['sequence' => 1, 'lines' => [['x']], 'dropped' => 0, 'skipped' => 0]]],
        'negative drop count' => ['log.lines', ['type' => 'log.lines', 'id' => str_repeat('a', 32), 'data' => ['sequence' => 1, 'lines' => [], 'dropped' => -1, 'skipped' => 0]]],
        'missing reason' => ['log.ended', ['type' => 'log.ended', 'id' => str_repeat('a', 32), 'data' => []]],
    ])->throws(RealtimeProtocolException::class);
});
