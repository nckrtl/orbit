<?php

declare(strict_types=1);

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;

function scenarioResult(ScenarioStatus $primary, ?ScenarioStatus $effective = null): ScenarioResult
{
    return new ScenarioResult(
        str_repeat('a', 40),
        new ScenarioRunId(str_repeat('b', 32)),
        new ScenarioId('cold-four-node'),
        new AttemptId(str_repeat('c', 32)),
        'cold',
        $primary,
        $effective ?? $primary,
        [
            'id' => 'cold-four-node',
            'declared_inputs' => ['resources/host/prepare-node.sh' => str_repeat('d', 64)],
        ],
        str_repeat('e', 64),
        str_repeat('f', 64),
        [[
            'phase' => 'setup',
            'name' => 'construct',
            'required' => true,
            'outcome' => $primary->value,
        ]],
        ['construct' => ['duration_ms' => 12, 'passed' => $primary === ScenarioStatus::Passed]],
        ['passed' => $primary === ScenarioStatus::Passed, 'probes' => []],
        $primary === ScenarioStatus::Passed ? [] : ['redacted diagnostic'],
        [
            'removed' => ['vm'],
            'absent' => [],
            'refused' => [],
            'remaining' => [],
            'recovery_command' => 'bin/e2e-scenarios cleanup '.str_repeat('b', 32).' cold-four-node '.str_repeat('c', 32),
        ],
        '2026-09-10T12:00:00.000000+00:00',
        '2026-09-10T12:00:01.000000+00:00',
    );
}

it('round trips complete identity, inputs, timings, actions, verification, diagnostics, and cleanup', function (): void {
    $result = scenarioResult(ScenarioStatus::Passed);

    expect(ScenarioResult::fromArray($result->toArray())->toArray())->toBe($result->toArray());
    expect($result->toArray())
        ->toHaveKeys([
            'candidate_sha', 'run_id', 'scenario_id', 'attempt_id', 'lane', 'definition',
            'definition_fingerprint', 'recipe_fingerprint', 'actions', 'phase_timings',
            'verification', 'diagnostics', 'cleanup', 'started_at', 'finished_at',
        ]);
});

it('round trips a snapshot-lane result', function (): void {
    $cold = scenarioResult(ScenarioStatus::Passed);
    $snapshot = new ScenarioResult(
        $cold->candidate,
        $cold->run,
        new ScenarioId('snapshot-scenario-lifecycle'),
        $cold->attempt,
        'snapshot',
        $cold->primaryStatus,
        $cold->status,
        $cold->definition,
        $cold->definitionFingerprint,
        $cold->recipeFingerprint,
        $cold->actions,
        $cold->phaseTimings,
        $cold->verification,
        $cold->diagnostics,
        $cold->cleanup,
        $cold->startedAt,
        $cold->finishedAt,
    );

    expect(ScenarioResult::fromArray($snapshot->toArray())->lane)->toBe('snapshot');
});

it('serializes each supported primary outcome', function (ScenarioStatus $status): void {
    expect(scenarioResult($status)->toArray()['status'])->toBe($status->value);
})->with(ScenarioStatus::cases());

it('retains the primary failure when cleanup makes the effective result infrastructure-invalid', function (): void {
    $result = scenarioResult(ScenarioStatus::Failed, ScenarioStatus::InfrastructureError);

    expect($result->primaryStatus)->toBe(ScenarioStatus::Failed);
    expect($result->status)->toBe(ScenarioStatus::InfrastructureError);
});

it('adds the first phase to an empty retained attempt object', function (): void {
    $paths = new StatePaths(temporaryPath('scenario-state-', 5));
    $runs = new ScenarioRunStore(new AtomicJsonStore($paths));
    $run = new ScenarioRunId(str_repeat('b', 32));
    $scenario = new ScenarioId('cold-four-node');
    $attempt = new AttemptId(str_repeat('c', 32));
    $operation = new OperationId(str_repeat('d', 32));
    $recipe = TopologyRecipe::coldAcceptance();
    $definition = new ScenarioDefinition(
        $scenario,
        'cold',
        $recipe,
        [new ScenarioAction('setup', 'construct', 60)],
        ['apps/e2e/resources/guest/prepare-node.sh' => str_repeat('e', 64)],
        TopologyEndState::complete($recipe),
        false,
        'cold scenario state',
    );
    $target = TopologyTarget::disposableScenario($run, $scenario, $attempt, $recipe);
    $runs->beginAttempt(
        $run,
        $definition,
        $attempt,
        $operation,
        $target,
        str_repeat('a', 40),
        '2026-09-10T12:00:00.000000+00:00',
    );

    $runs->recordPhase($run, $scenario, $attempt, 'preflight', ['passed' => true]);

    expect($runs->attempt($run, $scenario, $attempt)['phase_timings'])
        ->toBe(['preflight' => ['passed' => true]]);
});
