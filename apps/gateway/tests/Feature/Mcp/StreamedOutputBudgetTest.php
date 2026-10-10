<?php

declare(strict_types=1);

use App\Http\Mcp\StreamedOutputBudget;

/**
 * @param  list<string>  $chunks
 * @return list<array<string, mixed>>
 */
function streamed_events(array $chunks): array
{
    $events = [['type' => 'phase', 'sequence' => 1, 'phase' => 'preparing']];

    foreach ($chunks as $chunk) {
        $events[] = ['type' => 'output', 'sequence' => count($events) + 1, 'stream' => 'stdout', 'data_base64' => base64_encode($chunk)];
    }

    $events[] = ['type' => 'result', 'sequence' => count($events) + 1, 'status' => 'succeeded'];

    return $events;
}

/** @param list<mixed> $events */
function streamed_output(array $events): string
{
    $output = '';

    foreach ($events as $event) {
        if (is_array($event) && $event['type'] === 'output') {
            $output .= base64_decode((string) $event['data_base64'], true);
        }
    }

    return $output;
}

it('leaves output that fits unchanged', function (): void {
    $events = streamed_events(['first line', 'second line']);

    expect(new StreamedOutputBudget(4_096)->apply($events))->toBe($events);
});

it('keeps the start and end of long output around one truncation marker, and the result last', function (array $chunks): void {
    $events = streamed_events($chunks);
    $output = implode('', $chunks);

    $bounded = new StreamedOutputBudget(8_192)->apply($events);
    $types = array_column($bounded, 'type');
    $marker = array_search('output_truncated', $types, true);
    $head = streamed_output(array_slice($bounded, 0, (int) $marker));
    $tail = streamed_output(array_slice($bounded, (int) $marker + 1));
    $outputBytes = array_sum(array_map(
        static fn (array $event): int => strlen((string) json_encode($event, JSON_UNESCAPED_SLASHES)) + 1,
        array_filter($bounded, static fn (array $event): bool => $event['type'] === 'output'),
    ));

    expect($outputBytes)->toBeLessThanOrEqual(8_192)
        ->and(array_count_values($types)['output_truncated'])->toBe(1)
        ->and($head)->not->toBe('')
        ->and($tail)->not->toBe('')
        ->and($output)->toStartWith($head)
        ->and($output)->toEndWith($tail)
        ->and(strlen($head) + strlen($tail) + $bounded[$marker]['dropped_bytes'])->toBe(strlen($output))
        ->and($bounded[0])->toBe($events[0])
        ->and(end($bounded))->toBe(end($events));
})->with([
    'a few large chunks' => [[str_repeat('a', 16_384), str_repeat('b', 16_384), str_repeat('c', 16_384)]],
    'many small chunks' => [array_map(static fn (int $line): string => sprintf("line %05d\n", $line), range(1, 5_000))],
]);
