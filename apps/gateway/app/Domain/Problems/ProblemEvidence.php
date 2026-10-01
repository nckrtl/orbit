<?php

declare(strict_types=1);

namespace App\Domain\Problems;

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

        foreach (['request_ids' => 5, 'paths' => 5] as $key => $cap) {
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
