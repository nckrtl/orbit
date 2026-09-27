<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

final readonly class AgentThreadObserver
{
    public function __construct(private AgentDriverRegistry $drivers, private ?TaskBroadcasts $broadcasts = null) {}

    public function observe(AgentThread $thread): ?AgentObservation
    {
        if (str_starts_with($thread->external_id, TaskAgentSpawner::PendingPrefix)) {
            return null;
        }
        try {
            $observation = $this->drivers->get($thread->driver)->observe($thread);
        } catch (AgentDriverException) {
            $this->persist($thread, ['observation_error' => 'Agent observation unavailable.']);

            return null;
        }

        return $this->record($thread, $observation) ? $observation : null;
    }

    public function record(AgentThread $thread, AgentObservation $observation): bool
    {
        $values = ['observed_at' => now(), 'observation_error' => null];
        if ($observation->state !== null) {
            $values['state'] = $observation->state;
            $values['error'] = $observation->error;
        }
        $partialMetrics = ($observation->metricsCheckpoint['t3_metrics_partial'] ?? false) === true;
        $tokens = $observation->tokens;
        $durableTokens = $observation->metricsCheckpoint['t3_observed_total_processed_tokens'] ?? null;
        if (is_int($durableTokens) && ($tokens === null || $tokens < $durableTokens)) {
            $tokens = $durableTokens;
        }
        foreach ([
            'tokens' => $tokens,
            'input_tokens' => $observation->inputTokens,
            'cached_input_tokens' => $observation->cachedInputTokens,
            'output_tokens' => $observation->outputTokens,
            'model_calls' => $observation->modelCalls,
            'peak_context_tokens' => $observation->peakContextTokens,
            'lines_added' => $observation->linesAdded,
            'lines_deleted' => $observation->linesDeleted,
        ] as $key => $value) {
            if ($value !== null || ($partialMetrics && in_array($key, ['input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens'], true))) {
                $values[$key] = $value;
            }
        }
        if ($observation->metricsCheckpoint !== null) {
            $values = [...$values, ...$observation->metricsCheckpoint];
        }

        return $this->persist($thread, $values);
    }

    /** @param array<string, mixed> $values */
    private function persist(AgentThread $thread, array $values): bool
    {
        $version = $thread->observation_version ?? 0;
        $before = [$thread->state, $thread->error, $thread->observation_error];
        $query = AgentThread::query()->whereKey($thread->id)->where('observation_version', $version);
        if (array_key_exists('t3_event_sequence', $values)) {
            $sequence = $thread->t3_event_sequence;
            if ($sequence === null) {
                $query->whereNull('t3_event_sequence');
            } else {
                $query->where('t3_event_sequence', $sequence);
            }
        }
        $updated = $query->update([
            ...$values, 'observation_version' => $version + 1,
        ]);
        $thread->refresh();

        if ($updated === 1 && $before !== [$thread->state, $thread->error, $thread->observation_error]) {
            ($this->broadcasts ?? app(TaskBroadcasts::class))->threadChanged($thread->id);
        }

        return $updated === 1;
    }
}
