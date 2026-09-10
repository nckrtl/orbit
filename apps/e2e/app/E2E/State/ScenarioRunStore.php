<?php

declare(strict_types=1);

namespace App\E2E\State;

use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAggregate;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;

final readonly class ScenarioRunStore
{
    public function __construct(private AtomicJsonStore $json) {}

    /** @param list<ScenarioDefinition> $definitions */
    public function beginRun(ScenarioRunId $run, string $candidate, array $definitions, string $startedAt): void
    {
        $this->json->write($this->runPath($run), [
            'schema' => 1,
            'run_id' => $run->value,
            'candidate_sha' => $candidate,
            'scenario_ids' => array_map(static fn (ScenarioDefinition $definition): string => $definition->id->value, $definitions),
            'started_at' => $startedAt,
            'aggregate' => $this->aggregatePath($run),
        ]);
    }

    public function beginAttempt(
        ScenarioRunId $run,
        ScenarioDefinition $definition,
        AttemptId $attempt,
        OperationId $operation,
        TopologyTarget $target,
        string $candidate,
        string $startedAt,
    ): void {
        $this->json->write($this->attemptPath($run, $definition->id, $attempt), [
            'schema' => 1,
            'candidate_sha' => $candidate,
            'run_id' => $run->value,
            'scenario_id' => $definition->id->value,
            'attempt_id' => $attempt->value,
            'operation_id' => $operation->value,
            'definition_fingerprint' => $definition->fingerprint(),
            'recipe' => $definition->recipe->toArray(),
            'recipe_fingerprint' => $definition->recipe->fingerprint(),
            'network' => $target->network(),
            'instances' => array_map($target->instance(...), $definition->recipe->nodeKeys()),
            'started_at' => $startedAt,
            'construction_inputs' => null,
            'phase_timings' => [],
            'primary_status' => null,
            'status' => null,
            'cleanup' => null,
            'result' => $this->resultPath($run, $definition->id, $attempt),
            'recovery_command' => $this->recoveryCommand($run, $definition->id, $attempt),
        ]);
    }

    /** @param array<string, mixed> $inputs */
    public function recordConstructionInputs(
        ScenarioRunId $run,
        ScenarioId $scenario,
        AttemptId $attempt,
        array $inputs,
    ): void {
        $state = $this->attempt($run, $scenario, $attempt);
        $state['construction_inputs'] = $inputs;
        $this->json->write($this->attemptPath($run, $scenario, $attempt), $state);
    }

    /** @param array<string, mixed> $timing */
    public function recordPhase(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt, string $phase, array $timing): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $phase) !== 1) {
            throw new InvalidArgumentException('The scenario phase name is invalid.');
        }
        $state = $this->attempt($run, $scenario, $attempt);
        $phaseTimings = $state['phase_timings'] ?? null;
        if (! is_array($phaseTimings) || array_is_list($phaseTimings)) {
            throw new InvalidArgumentException('The scenario phase state is invalid.');
        }
        $phaseTimings[$phase] = $timing;
        $state['phase_timings'] = $phaseTimings;
        $this->json->write($this->attemptPath($run, $scenario, $attempt), $state);
    }

    public function writeResult(ScenarioResult $result): void
    {
        $this->json->write($this->resultPath($result->run, $result->scenario, $result->attempt), $result->toArray());
        $state = $this->attempt($result->run, $result->scenario, $result->attempt);
        $state['primary_status'] = $result->primaryStatus->value;
        $state['status'] = $result->status->value;
        $state['cleanup'] = $result->cleanup;
        $state['finished_at'] = $result->finishedAt;
        $this->json->write($this->attemptPath($result->run, $result->scenario, $result->attempt), $state);
    }

    public function result(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt): ?ScenarioResult
    {
        $value = $this->json->read($this->resultPath($run, $scenario, $attempt));

        return $value === null ? null : ScenarioResult::fromArray($value);
    }

    /** @return array<array-key, mixed> */
    public function attempt(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt): array
    {
        return $this->json->read($this->attemptPath($run, $scenario, $attempt))
            ?? throw new InvalidArgumentException('The exact scenario attempt record is absent.');
    }

    public function writeAggregate(ScenarioAggregate $aggregate): void
    {
        $this->json->write($this->aggregatePath($aggregate->run), $aggregate->toArray());
    }

    public function aggregatePath(ScenarioRunId $run): string
    {
        return "scenarios/runs/{$run->value}/aggregate.json";
    }

    public function recoveryCommand(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt): string
    {
        return "bin/e2e-scenarios cleanup {$run->value} {$scenario->value} {$attempt->value}";
    }

    private function runPath(ScenarioRunId $run): string
    {
        return "scenarios/runs/{$run->value}/run.json";
    }

    private function attemptPath(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt): string
    {
        return "scenarios/runs/{$run->value}/{$scenario->value}/{$attempt->value}/attempt.json";
    }

    private function resultPath(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt): string
    {
        return "scenarios/runs/{$run->value}/{$scenario->value}/{$attempt->value}/result.json";
    }
}
