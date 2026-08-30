<?php

declare(strict_types=1);

namespace Tests\Live\Support;

use InvalidArgumentException;

/**
 * Wall-clock seconds per live phase, plus the `duration_ms` breakdown a
 * harness operation journal recorded for it, rendered as one summary line.
 */
final class PhaseTimings
{
    /** @var array<string, array{seconds: float, journal_ms: array<string, float>}> */
    private array $phases = [];

    public function record(string $phase, float $seconds): void
    {
        $this->phases[$phase] = ['seconds' => $seconds, 'journal_ms' => $this->phases[$phase]['journal_ms'] ?? []];
    }

    /**
     * @param list<array<array-key, mixed>> $entries
     * @mago-expect analysis:mixed-assignment Journal entries are validated one field at a time.
     */
    public function mergeJournal(string $phase, array $entries, string $event): void
    {
        if (! array_key_exists($phase, $this->phases)) {
            throw new InvalidArgumentException("Phase [{$phase}] was not recorded.");
        }
        foreach ($entries as $entry) {
            if (($entry['event'] ?? null) !== $event || ! is_array($entry['duration_ms'] ?? null)) {
                continue;
            }
            foreach ($entry['duration_ms'] as $step => $milliseconds) {
                if (is_string($step) && (is_float($milliseconds) || is_int($milliseconds))) {
                    $this->phases[$phase]['journal_ms'][$step] = (float) $milliseconds;
                }
            }
        }
    }

    public function summary(): string
    {
        $parts = [];
        foreach ($this->phases as $phase => $timing) {
            $part = sprintf('%s=%.3fs', $phase, $timing['seconds']);
            if ($timing['journal_ms'] !== []) {
                $steps = [];
                foreach ($timing['journal_ms'] as $step => $milliseconds) {
                    $steps[] = sprintf('%s=%dms', $step, (int) round($milliseconds));
                }
                $part .= ' ['.implode(' ', $steps).']';
            }
            $parts[] = $part;
        }

        return 'lifecycle timings: '.implode(' ', $parts);
    }

    /** @return array<string, array{seconds: float, journal_ms: array<string, float>}> */
    public function toArray(): array
    {
        return $this->phases;
    }
}
