<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioProcessResult;
use App\E2E\Value\ScenarioRunId;
use Closure;
use Symfony\Component\Process\Process;

final readonly class ScenarioPestProcess
{
    public function __construct(
        private string $projectRoot,
        private ?Closure $runner = null,
    ) {}

    public function run(
        ScenarioDefinition $definition,
        string $candidate,
        ScenarioRunId $run,
        AttemptId $attempt,
        OperationId $operation,
        string $repository,
        string $primary,
    ): ScenarioProcessResult {
        if ($this->runner !== null) {
            $result = ($this->runner)($definition, $candidate, $run, $attempt, $operation, $repository, $primary);

            return $result instanceof ScenarioProcessResult ? $result : new ScenarioProcessResult(70, 'Invalid process fake.');
        }

        $process = new Process([
            PHP_BINARY,
            'vendor/bin/pest',
            '--no-tia',
            '--compact',
            'tests/Scenario/ColdTopologyAcceptanceTest.php',
            '--filter='.$definition->pestFilter,
        ], $this->projectRoot, [
            'ORBIT_SCENARIO_CANDIDATE_SHA' => $candidate,
            'ORBIT_SCENARIO_REPOSITORY' => $repository,
            'ORBIT_SCENARIO_PRIMARY_ROOT' => $primary,
            'ORBIT_SCENARIO_RUN_ID' => $run->value,
            'ORBIT_SCENARIO_ID' => $definition->id->value,
            'ORBIT_SCENARIO_ATTEMPT_ID' => $attempt->value,
            'ORBIT_SCENARIO_OPERATION_ID' => $operation->value,
        ]);
        $process->setTimeout(null);
        $process->run();

        return new ScenarioProcessResult(
            $process->getExitCode() ?? 70,
            $process->getOutput().$process->getErrorOutput(),
        );
    }
}
