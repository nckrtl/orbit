<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class ScenarioResult
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, array<string, mixed>>  $phaseTimings
     * @param  array<string, mixed>|null  $verification
     * @param  list<string>  $diagnostics
     * @param  array<string, mixed>  $cleanup
     */
    public function __construct(
        public string $candidate,
        public ScenarioRunId $run,
        public ScenarioId $scenario,
        public AttemptId $attempt,
        public string $lane,
        public ScenarioStatus $primaryStatus,
        public ScenarioStatus $status,
        public array $definition,
        public string $definitionFingerprint,
        public string $recipeFingerprint,
        public array $actions,
        public array $phaseTimings,
        public ?array $verification,
        public array $diagnostics,
        public array $cleanup,
        public string $startedAt,
        public string $finishedAt,
    ) {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
            throw new InvalidArgumentException('The scenario result candidate is invalid.');
        }
        if ($lane !== 'cold') {
            throw new InvalidArgumentException('The scenario result lane is invalid.');
        }
        foreach ([$definitionFingerprint, $recipeFingerprint] as $fingerprint) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('A scenario result fingerprint is invalid.');
            }
        }
        if ($status !== ScenarioStatus::InfrastructureError && $status !== $primaryStatus) {
            throw new InvalidArgumentException('The scenario effective outcome is invalid.');
        }
        if (! self::isTimestamp($startedAt) || ! self::isTimestamp($finishedAt)) {
            throw new InvalidArgumentException('A scenario result timestamp is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'candidate_sha' => $this->candidate,
            'run_id' => $this->run->value,
            'scenario_id' => $this->scenario->value,
            'attempt_id' => $this->attempt->value,
            'lane' => $this->lane,
            'primary_status' => $this->primaryStatus->value,
            'status' => $this->status->value,
            'definition' => $this->definition,
            'definition_fingerprint' => $this->definitionFingerprint,
            'recipe_fingerprint' => $this->recipeFingerprint,
            'actions' => $this->actions,
            'phase_timings' => $this->phaseTimings,
            'verification' => $this->verification,
            'diagnostics' => $this->diagnostics,
            'cleanup' => $this->cleanup,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = [
            'schema', 'candidate_sha', 'run_id', 'scenario_id', 'attempt_id', 'lane', 'primary_status', 'status',
            'definition', 'definition_fingerprint', 'recipe_fingerprint', 'actions', 'phase_timings', 'verification',
            'diagnostics', 'cleanup', 'started_at', 'finished_at',
        ];
        if (array_keys($value) !== $keys || ($value['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('The scenario result schema is invalid.');
        }
        foreach (['candidate_sha', 'run_id', 'scenario_id', 'attempt_id', 'lane', 'definition_fingerprint', 'recipe_fingerprint', 'started_at', 'finished_at'] as $key) {
            if (! is_string($value[$key])) {
                throw new InvalidArgumentException('The scenario result schema is invalid.');
            }
        }
        foreach (['definition', 'actions', 'phase_timings', 'diagnostics', 'cleanup'] as $key) {
            if (! is_array($value[$key])) {
                throw new InvalidArgumentException('The scenario result schema is invalid.');
            }
        }
        if ($value['verification'] !== null && ! is_array($value['verification'])) {
            throw new InvalidArgumentException('The scenario result schema is invalid.');
        }
        $primary = is_string($value['primary_status'] ?? null) ? ScenarioStatus::tryFrom($value['primary_status']) : null;
        $status = is_string($value['status'] ?? null) ? ScenarioStatus::tryFrom($value['status']) : null;
        if ($primary === null || $status === null) {
            throw new InvalidArgumentException('The scenario result status is invalid.');
        }
        /** @var array<string, mixed> $definition */
        $definition = $value['definition'];
        /** @var list<array<string, mixed>> $actions */
        $actions = $value['actions'];
        /** @var array<string, array<string, mixed>> $phaseTimings */
        $phaseTimings = $value['phase_timings'];
        /** @var array<string, mixed>|null $verification */
        $verification = $value['verification'];
        /** @var list<string> $diagnostics */
        $diagnostics = $value['diagnostics'];
        /** @var array<string, mixed> $cleanup */
        $cleanup = $value['cleanup'];

        return new self(
            $value['candidate_sha'],
            new ScenarioRunId($value['run_id']),
            new ScenarioId($value['scenario_id']),
            new AttemptId($value['attempt_id']),
            $value['lane'],
            $primary,
            $status,
            $definition,
            $value['definition_fingerprint'],
            $value['recipe_fingerprint'],
            $actions,
            $phaseTimings,
            $verification,
            $diagnostics,
            $cleanup,
            $value['started_at'],
            $value['finished_at'],
        );
    }

    private static function isTimestamp(string $value): bool
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+00:00\z/D', $value) === 1;
    }
}
