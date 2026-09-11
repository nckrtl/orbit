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
        $worker = $this->start($definition, $candidate, $run, $attempt, $operation, $repository, $primary);
        while (($result = $worker->poll()) === null) {
            usleep(10_000);
        }

        return $result;
    }

    public function start(
        ScenarioDefinition $definition,
        string $candidate,
        ScenarioRunId $run,
        AttemptId $attempt,
        OperationId $operation,
        string $repository,
        string $primary,
    ): ScenarioWorkerProcess {
        if ($this->runner !== null) {
            $result = ($this->runner)($definition, $candidate, $run, $attempt, $operation, $repository, $primary);

            if ($result instanceof ScenarioWorkerProcess) {
                return $result;
            }

            return ScenarioWorkerProcess::completed(
                $result instanceof ScenarioProcessResult
                    ? $result
                    : new ScenarioProcessResult(70, 'Invalid process fake.'),
            );
        }

        $configuration = match ($definition->lane) {
            'cold' => 'phpunit.scenario-cold.xml',
            'snapshot' => 'phpunit.scenario-snapshot.xml',
            default => throw new \InvalidArgumentException('The scenario lane is invalid.'),
        };
        $process = new Process([
            PHP_BINARY,
            'vendor/bin/pest',
            "--configuration={$configuration}",
            '--tia',
            '--fresh',
            '--compact',
        ], $this->projectRoot, [
            'ORBIT_SCENARIO_CANDIDATE_SHA' => $candidate,
            'ORBIT_SCENARIO_REPOSITORY' => $repository,
            'ORBIT_SCENARIO_PRIMARY_ROOT' => $primary,
            'ORBIT_SCENARIO_RUN_ID' => $run->value,
            'ORBIT_SCENARIO_ID' => $definition->id->value,
            'ORBIT_SCENARIO_ATTEMPT_ID' => $attempt->value,
            'ORBIT_SCENARIO_OPERATION_ID' => $operation->value,
            'ORBIT_E2E_OPERATION_ID' => $operation->value,
            'ORBIT_SCENARIO_TIA_DIRECTORY' => rtrim($primary, '/')
                ."/.e2e/scenarios/runs/{$run->value}/{$definition->id->value}/{$attempt->value}/tia",
        ]);
        $process->setTimeout(null);
        $process->start(static function (): void {});

        return ScenarioWorkerProcess::fromSymfonyProcess($process);
    }
}
