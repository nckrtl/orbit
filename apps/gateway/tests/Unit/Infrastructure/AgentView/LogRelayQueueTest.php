<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\LogRelayQueue;

/** @return array{LogRelayQueue, object{now: float}} */
function relay_queue(): array
{
    $clock = new class
    {
        public float $now = 100.0;
    };

    return [new LogRelayQueue(static fn (): float => $clock->now, relay: 'relay-1'), $clock];
}

/**
 * @param  list<string>  $lines
 * @return array<string, mixed>
 */
function queue_event(string $stream, array $lines, int $dropped = 0, int $skipped = 0): array
{
    return ['stream' => $stream, 'sequence' => 1, 'lines' => $lines, 'dropped' => $dropped, 'skipped' => $skipped];
}

/**
 * @param  array<string, mixed>|null  $batch
 * @return list<string>
 */
function batch_summary(?array $batch): array
{
    return array_map(static fn (array $item): string => match ($item['type']) {
        'lines' => $item['stream'][0].':'.implode(',', $item['lines']),
        'end' => $item['stream'][0].':end:'.$item['reason'],
        'agent_left' => "left:{$item['node']}",
    }, $batch['items'] ?? []);
}

const STREAM_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const STREAM_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

describe('the log relay queue', function (): void {
    it('keeps events in arrival order and joins lines that follow lines of the same stream', function (): void {
        [$queue] = relay_queue();
        $queue->lines(3, queue_event(STREAM_A, ['one']));
        $queue->lines(3, queue_event(STREAM_A, ['two']));
        $queue->lines(3, queue_event(STREAM_B, ['three']));
        $queue->end(3, ['stream' => STREAM_A, 'reason' => 'source_unavailable']);
        $queue->agentLeft(3);

        $batch = $queue->take();

        expect($batch['relay'])->toBe('relay-1')
            ->and(batch_summary($batch))->toBe(['a:one,two', 'b:three', 'a:end:source_unavailable', 'left:3'])
            ->and(array_column($batch['items'], 'item'))->toBe([1, 2, 3, 4]);
    });

    it('drops malformed events and cleans each line', function (): void {
        [$queue] = relay_queue();
        $queue->lines(3, queue_event('not-a-stream', ['x']));
        $queue->lines(3, ['stream' => STREAM_A, 'lines' => 'x']);
        $queue->lines(3, ['stream' => STREAM_A, 'lines' => [1]]);
        $queue->lines(3, queue_event(STREAM_A, ['x'], dropped: -1));
        $queue->lines(3, queue_event(STREAM_A, array_fill(0, LogRelayQueue::MaxLines + 1, 'x')));
        $queue->lines(3, queue_event(STREAM_A, ["cr\r", str_repeat('y', 9_000)]));

        $lines = $queue->take()['items'][0]['lines'];

        expect($queue->pending())->toBe([])
            ->and($lines[0])->toBe('cr')
            ->and($lines[1])->toEndWith(' [truncated]')
            ->and(strlen($lines[1]))->toBeLessThanOrEqual(LogRelayQueue::MaxLineBytes);
    });

    it('drops lines above the rate for each stream and counts them', function (): void {
        [$queue, $clock] = relay_queue();
        $line = str_repeat('x', 8_191);
        $queue->lines(3, queue_event(STREAM_A, array_fill(0, 40, $line), dropped: 1));

        $first = $queue->take()['items'][0];
        $queue->succeeded(1);
        $clock->now += 1;
        $queue->lines(3, queue_event(STREAM_A, array_fill(0, 10, $line)));
        $second = $queue->take()['items'][0];

        expect(count($first['lines']))->toBe(32)
            ->and($first['dropped'])->toBe(9)
            ->and(count($second['lines']))->toBe(8)
            ->and($second['dropped'])->toBe(2);
    });

    it('hands one batch at a time and bounds its size', function (): void {
        [$queue] = relay_queue();

        foreach (range(1, 40) as $index) {
            $queue->lines(3, queue_event($index % 2 === 0 ? STREAM_A : STREAM_B, [str_repeat('x', 8_000)]));
        }

        $batch = $queue->take();
        $bytes = array_sum(array_map(static fn (array $item): int => strlen(implode("\n", $item['lines'])) + 1, $batch['items']));

        expect($bytes)->toBeLessThanOrEqual(LogRelayQueue::BatchBytes)
            ->and($queue->take())->toBeNull()
            ->and($queue->pending())->not->toBe([]);
    });

    it('puts a failed batch back in front, and no later lines join what a run already had', function (): void {
        [$queue] = relay_queue();
        $queue->lines(3, queue_event(STREAM_A, ['one']));
        $taken = $queue->take();
        $queue->failed();
        // The run may have published part of item 1, so its lines must stay exactly as they were.
        $queue->lines(3, queue_event(STREAM_A, ['two']));
        $queue->lines(3, queue_event(STREAM_A, ['three']));

        $again = $queue->take();

        expect($again['items'][0])->toBe($taken['items'][0])
            ->and(batch_summary($again))->toBe(['a:one', 'a:two,three']);
    });

    it('ends a stream whose waiting lines pass the backlog with relay_behind and ignores it afterwards', function (): void {
        [$queue, $clock] = relay_queue();
        $line = str_repeat('x', 8_000);
        $queue->lines(3, queue_event(STREAM_B, ['other']));

        // A stalled relay: the rate refills every second, and the waiting lines grow.
        foreach (range(1, 20) as $second) {
            $queue->lines(3, queue_event(STREAM_A, array_fill(0, 8, $line)));
            $clock->now += 1;
        }

        $queue->lines(3, queue_event(STREAM_A, ['after the end']));
        $summary = batch_summary($queue->take());

        expect($summary)->toBe(['b:other', 'a:end:relay_behind'])
            ->and($queue->pending())->toBe([]);
    });

    it('ends the streams with waiting lines after repeated failed runs', function (): void {
        [$queue] = relay_queue();
        $queue->lines(3, queue_event(STREAM_A, ['one']));
        $queue->agentLeft(4);

        foreach (range(1, LogRelayQueue::MaxAttempts - 1) as $attempt) {
            $queue->take();
            $queue->failed();
        }

        expect(batch_summary(['items' => $queue->pending()]))->toBe(['a:one', 'left:4']);

        $queue->take();
        $queue->failed();

        expect(batch_summary(['items' => $queue->pending()]))->toBe(['left:4', 'a:end:relay_behind']);
    });

    it('asks for a sweep every five seconds until a run finds no open stream', function (): void {
        [$queue, $clock] = relay_queue();

        expect($queue->take())->toBeNull();

        $queue->streamsChanged();
        expect($queue->take()['sweep'] ?? null)->toBeTrue();
        $queue->succeeded(1);
        expect($queue->take())->toBeNull();

        $clock->now += LogRelayQueue::SweepSeconds;
        expect($queue->take()['sweep'] ?? null)->toBeTrue();
        $queue->succeeded(0);

        $clock->now += LogRelayQueue::SweepSeconds;
        expect($queue->take())->toBeNull()
            ->and($queue->streamsMayBeOpen())->toBeFalse();

        $queue->streamsChanged();
        expect($queue->take()['sweep'] ?? null)->toBeTrue();
    });

    it('keeps sweeping when a stream may have opened while the run counted none', function (): void {
        [$queue, $clock] = relay_queue();
        $queue->streamsChanged();
        $queue->take();
        $queue->streamsChanged();
        $queue->succeeded(0);
        $clock->now += LogRelayQueue::SweepSeconds;

        expect($queue->take()['sweep'] ?? null)->toBeTrue();
    });
});
