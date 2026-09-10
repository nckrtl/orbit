<?php

declare(strict_types=1);

use App\E2E\ScenarioPestProcess;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyRecipe;

it('routes each scenario lane to its dedicated Pest acceptance file', function (string $lane, string $acceptanceFile): void {
    $project = temporaryPath('scenario-pest-process-', 5);
    $primary = temporaryPath('scenario-pest-primary-', 5);
    mkdir("{$project}/vendor/bin", 0o700, true);
    mkdir($primary, 0o700, true);
    file_put_contents("{$project}/vendor/bin/pest", <<<'PHP'
        <?php

        declare(strict_types=1);

        $primary = getenv('ORBIT_SCENARIO_PRIMARY_ROOT');
        if (! is_string($primary)) {
            exit(64);
        }
        file_put_contents("{$primary}/arguments.json", json_encode([
            'arguments' => $argv,
            'operation_id' => getenv('ORBIT_E2E_OPERATION_ID'),
        ], JSON_THROW_ON_ERROR));
        PHP);
    $recipe = TopologyRecipe::registered();
    $definition = new ScenarioDefinition(
        new ScenarioId("{$lane}-flow"),
        $lane,
        $recipe,
        $lane === 'snapshot'
            ? [
                new ScenarioAction('setup', 'prepare', 60),
                new ScenarioAction('exercise', 'lifecycle', 60),
                new ScenarioAction('assertion', 'verify', 60),
            ]
            : [new ScenarioAction('setup', 'prepare', 60)],
        ['apps/e2e/resources/guest/prepare-node.sh' => str_repeat('a', 64)],
        TopologyEndState::complete($recipe),
        false,
        "{$lane}-scenario-filter",
    );
    $process = new ScenarioPestProcess($project);

    $result = $process->run(
        $definition,
        str_repeat('a', 40),
        new ScenarioRunId(str_repeat('b', 32)),
        new AttemptId(str_repeat('c', 32)),
        new OperationId(str_repeat('d', 32)),
        dirname(__DIR__, 5),
        $primary,
    );

    expect($result->exitCode)->toBe(0);
    $invocation = json_decode(
        (string) file_get_contents("{$primary}/arguments.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($invocation['arguments'])
        ->toContain($acceptanceFile, "--filter={$lane}-scenario-filter");
    expect($invocation['operation_id'])->toBe(str_repeat('d', 32));
})->with([
    'cold lane' => ['cold', 'tests/Scenario/ColdTopologyAcceptanceTest.php'],
    'snapshot lane' => ['snapshot', 'tests/Scenario/SnapshotTopologyAcceptanceTest.php'],
]);
