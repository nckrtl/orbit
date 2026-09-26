<?php

declare(strict_types=1);

namespace App\Domain\Logs;

/**
 * How far the log relay got with one stream (ADR 0153): the last `log.lines` sequence it published,
 * and the queue item and part that event carried. `relay` names the subscriber process that numbered
 * the items, so a restarted subscriber starts counting items again without reusing a sequence.
 *
 * A publish run that fails is repeated. The cursor lets the repeat skip every part already published,
 * so a viewer never gets a line twice under a new sequence.
 */
final readonly class LogRelayCursor
{
    public function __construct(
        public string $relay,
        public int $item,
        public int $part,
        public int $sequence,
    ) {}

    /** Whether the relay already published this part of this item. */
    public function covers(string $relay, int $item, int $part): bool
    {
        return $relay === $this->relay && ($item < $this->item || ($item === $this->item && $part <= $this->part));
    }

    /** @return array{relay: string, item: int, part: int, sequence: int} */
    public function toArray(): array
    {
        return ['relay' => $this->relay, 'item' => $this->item, 'part' => $this->part, 'sequence' => $this->sequence];
    }

    public static function fromArray(mixed $value): ?self
    {
        if (
            ! is_array($value) || ! is_string($value['relay'] ?? null) || ! is_int($value['item'] ?? null)
            || ! is_int($value['part'] ?? null) || ! is_int($value['sequence'] ?? null)
        ) {
            return null;
        }

        return new self($value['relay'], $value['item'], $value['part'], $value['sequence']);
    }
}
