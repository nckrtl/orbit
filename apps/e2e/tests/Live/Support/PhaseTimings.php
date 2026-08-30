<?php

declare(strict_types=1);

namespace Tests\Live\Support;

/** Wall-clock seconds per live phase, rendered as one summary line. */
final class PhaseTimings
{
    /** @var array<string, float> */
    private array $phases = [];

    public function record(string $phase, float $seconds): void
    {
        $this->phases[$phase] = $seconds;
    }

    public function summary(): string
    {
        $parts = [];
        foreach ($this->phases as $phase => $seconds) {
            $parts[] = sprintf('%s=%.3fs', $phase, $seconds);
        }

        return 'lifecycle timings: '.implode(' ', $parts);
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return $this->phases;
    }
}
