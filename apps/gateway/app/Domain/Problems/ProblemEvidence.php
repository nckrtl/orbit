<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Merges one observation into the bounded sample stored on a fingerprint.
 */
final readonly class ProblemEvidence
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $observation
     * @return array<string, mixed>
     */
    public function apply(array $evidence, array $observation): array
    {
        $times = $this->strings($evidence['observation_times'] ?? null);
        $at = $observation['at'] ?? null;

        if (is_string($at) && $at !== '') {
            $times[] = $at;
        }

        $evidence['observation_times'] = array_slice($times, -20);

        foreach (['request_ids' => 5, 'paths' => 5, 'evidence_urls' => 5] as $key => $cap) {
            if (! is_array($observation[$key] ?? null)) {
                continue;
            }

            $evidence[$key] = $this->appendStrings(
                $this->strings($evidence[$key] ?? null),
                $this->strings($observation[$key]),
                $cap,
            );
        }

        if (is_array($observation['activity_ids'] ?? null)) {
            $evidence['activity_ids'] = $this->appendInts(
                $this->ints($evidence['activity_ids'] ?? null),
                $this->ints($observation['activity_ids']),
                5,
            );
        }

        if (is_array($observation['assistance_task_ids'] ?? null)) {
            $evidence['assistance_task_ids'] = $this->appendInts(
                $this->ints($evidence['assistance_task_ids'] ?? null),
                $this->ints($observation['assistance_task_ids']),
                200,
            );
        }

        $excerpt = $observation['log_excerpt'] ?? null;

        if (is_string($excerpt) && $excerpt !== '') {
            $evidence['log_excerpt'] = mb_substr($excerpt, 0, 500);
        }

        $sourcePath = $observation['source_path'] ?? null;
        $storedPath = $evidence['source_path'] ?? null;

        if (is_string($sourcePath) && $sourcePath !== '' && (! is_string($storedPath) || $storedPath === '')) {
            $evidence['source_path'] = $sourcePath;
        }

        $message = $observation['error_message'] ?? null;

        if (is_string($message) && $message !== '') {
            $evidence['error_message'] = $message;
        }

        foreach (['summary', 'assistance_reason'] as $key) {
            $value = $observation[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $evidence[$key] = mb_substr($value, 0, 1000);
            }
        }

        foreach (['release_repository' => 255, 'release_id' => 64] as $key => $limit) {
            $value = $observation[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $evidence[$key] = mb_substr($value, 0, $limit);
            }
        }

        foreach (['expected', 'observed'] as $key) {
            if (! array_key_exists($key, $observation)) {
                continue;
            }

            $value = $observation[$key];

            if ($value === null || is_bool($value) || is_string($value)) {
                $evidence[$key] = $value;
            }
        }

        return $evidence;
    }

    /**
     * Rows written before occurrence windows have no signal counts.
     * Log and Activity timestamps are the collector clock, not the source time, so that history is dropped.
     * Doctor and assistance used the collector clock as the signal time, so those windows stay.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{evidence: array<string, mixed>, occurrences: int, reset_seen: bool}|null
     */
    public function legacyEpisode(array $evidence, ProblemSource $source): ?array
    {
        if (array_key_exists('observation_counts', $evidence) || array_key_exists('counted_blocks', $evidence)) {
            return null;
        }

        if ($source === ProblemSource::Log || $source === ProblemSource::Activity) {
            return $this->droppedLegacyEpisode($evidence);
        }

        $windows = $this->legacyWindows($this->strings($evidence['observation_times'] ?? null));

        if ($windows === []) {
            return $this->droppedLegacyEpisode($evidence, false);
        }

        ksort($windows);
        $blocks = [];
        $sampleTimes = [];
        $sampleCounts = [];

        foreach ($windows as $block => $window) {
            $blocks[] = $block;
        }

        foreach (array_slice($windows, -20, null, true) as $window) {
            $sampleTimes[] = $window['at'];
            $sampleCounts[] = $window['count'];
        }

        $evidence['observation_times'] = $sampleTimes;
        $evidence['observation_counts'] = $sampleCounts;
        $evidence['counted_blocks'] = $blocks;

        return [
            'evidence' => $evidence,
            'occurrences' => count($blocks),
            'reset_seen' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{evidence: array<string, mixed>, occurrences: int, reset_seen: bool}
     */
    private function droppedLegacyEpisode(array $evidence, bool $resetSeen = true): array
    {
        unset($evidence['observation_times']);
        $evidence['observation_counts'] = [];
        $evidence['counted_blocks'] = [];

        return [
            'evidence' => $evidence,
            'occurrences' => 0,
            'reset_seen' => $resetSeen,
        ];
    }

    /**
     * @param  list<string>  $times
     * @return array<int, array{at: string, count: int}>
     */
    private function legacyWindows(array $times): array
    {
        $windows = [];

        foreach ($times as $time) {
            try {
                $stamp = Carbon::parse($time)->utc()->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            $block = intdiv($stamp, 300);

            if (isset($windows[$block])) {
                $windows[$block]['count']++;

                continue;
            }

            $windows[$block] = ['at' => $time, 'count' => 1];
        }

        return $windows;
    }

    /** @return list<int> */
    public function ints(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ints = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $ints[] = $value;
            } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
                $ints[] = (int) $value;
            }
        }

        return $ints;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private function appendStrings(array $existing, array $incoming, int $cap): array
    {
        $merged = $existing;

        foreach ($incoming as $value) {
            $merged = array_values(array_filter(
                $merged,
                static fn (string $item): bool => $item !== $value,
            ));
            $merged[] = $value;
        }

        return array_slice($merged, -$cap);
    }

    /**
     * @param  list<int>  $existing
     * @param  list<int>  $incoming
     * @return list<int>
     */
    private function appendInts(array $existing, array $incoming, int $cap): array
    {
        $merged = $existing;

        foreach ($incoming as $value) {
            $merged = array_values(array_filter(
                $merged,
                static fn (int $item): bool => $item !== $value,
            ));
            $merged[] = $value;
        }

        return array_slice($merged, -$cap);
    }
}
