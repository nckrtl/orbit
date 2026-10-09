<?php

declare(strict_types=1);

namespace App\Http\Mcp;

/**
 * Bounds the `output` events of a streamed tool result, so the final `result` event always fits in the reply.
 *
 * A deploy streams each chunk of step output as a base64 `output` event. `execute_tools` refuses a reply over
 * `mcp.tool_search.max_output_bytes`, and the cap is checked only after the call has run, so a long deploy
 * would run but answer with no result. The output events may use half the cap: the first quarter and the last
 * quarter of it. One `output_truncated` event replaces the middle and names the bytes it dropped. Every other
 * event, the `result` event too, stays as it was.
 */
final readonly class StreamedOutputBudget
{
    private const int JsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private int $bytes) {}

    public static function fromConfig(): self
    {
        $limit = config('mcp.tool_search.max_output_bytes', 65_536);

        return new self(intdiv(is_int($limit) ? max(256, $limit) : 65_536, 2));
    }

    /**
     * @param  list<mixed>  $events
     * @return list<mixed>
     */
    public function apply(array $events): array
    {
        $outputs = [];

        foreach ($events as $index => $event) {
            $data = is_array($event) && ($event['type'] ?? null) === 'output' && is_string($event['data_base64'] ?? null)
                ? base64_decode($event['data_base64'], true)
                : false;

            if (is_string($data)) {
                $outputs[$index] = $data;
            }
        }

        $total = 0;

        foreach ($outputs as $index => $data) {
            $total += $this->cost($events[$index], $data);
        }

        if ($total <= $this->bytes) {
            return $events;
        }

        [$head, $boundary] = $this->head($events, $outputs, intdiv($this->bytes, 2));
        $tail = $this->tail($events, $outputs, $head, $boundary, $this->bytes - intdiv($this->bytes, 2));

        return $this->rebuild($events, $outputs, $head, $tail);
    }

    /**
     * The prefix length kept from each output event, from the start, and the event where the room ran out.
     *
     * @param  list<mixed>  $events
     * @param  array<int, string>  $outputs
     * @return array{array<int, int>, int}
     */
    private function head(array $events, array $outputs, int $room): array
    {
        $kept = [];
        $boundary = array_key_last($outputs);

        foreach ($outputs as $index => $data) {
            $cost = $this->cost($events[$index], $data);

            if ($cost <= $room) {
                $kept[$index] = strlen($data);
                $room -= $cost;

                continue;
            }

            $kept[$index] = $this->fits($events[$index], $data, $room);
            $boundary = $index;

            break;
        }

        return [$kept, (int) $boundary];
    }

    /**
     * The suffix length kept from each output event, from the end, down to the head's boundary event.
     *
     * @param  list<mixed>  $events
     * @param  array<int, string>  $outputs
     * @param  array<int, int>  $head
     * @return array<int, int>
     */
    private function tail(array $events, array $outputs, array $head, int $boundary, int $room): array
    {
        $kept = [];

        foreach (array_reverse($outputs, true) as $index => $data) {
            if ($index < $boundary) {
                break;
            }

            $available = $index === $boundary ? substr($data, $head[$index] ?? 0) : $data;
            $cost = $this->cost($events[$index], $available);

            if ($cost <= $room) {
                $kept[$index] = strlen($available);
                $room -= $cost;

                continue;
            }

            $kept[$index] = $this->fits($events[$index], $available, $room);

            break;
        }

        return $kept;
    }

    /**
     * @param  list<mixed>  $events
     * @param  array<int, string>  $outputs
     * @param  array<int, int>  $head
     * @param  array<int, int>  $tail
     * @return list<mixed>
     */
    private function rebuild(array $events, array $outputs, array $head, array $tail): array
    {
        $dropped = 0;

        foreach ($outputs as $index => $data) {
            $dropped += strlen($data) - ($head[$index] ?? 0) - ($tail[$index] ?? 0);
        }

        $bounded = [];
        $marked = false;

        foreach ($events as $index => $event) {
            $data = $outputs[$index] ?? null;

            if (! is_array($event) || $data === null || ($head[$index] ?? 0) + ($tail[$index] ?? 0) === strlen($data)) {
                $bounded[] = $event;

                continue;
            }

            if (($head[$index] ?? 0) > 0) {
                $bounded[] = [...$event, 'data_base64' => base64_encode(substr($data, 0, $head[$index]))];
            }

            if (! $marked && $dropped > 0) {
                $bounded[] = ['type' => 'output_truncated', 'dropped_bytes' => $dropped];
                $marked = true;
            }

            if (($tail[$index] ?? 0) > 0) {
                $bounded[] = [...$event, 'data_base64' => base64_encode(substr($data, -$tail[$index]))];
            }
        }

        return $bounded;
    }

    /** The longest part of $data, in whole base64 groups, whose event fits in $room bytes. */
    private function fits(mixed $event, string $data, int $room): int
    {
        $overhead = $this->cost($event, '');

        return max(0, min(strlen($data), intdiv(max(0, $room - $overhead), 4) * 3));
    }

    private function cost(mixed $event, string $data): int
    {
        $event = is_array($event) ? $event : [];

        return strlen(json_encode([...$event, 'data_base64' => base64_encode($data)], self::JsonFlags)) + 1;
    }
}
