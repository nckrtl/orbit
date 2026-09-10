<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAggregate;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyTarget;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ScenarioSuiteRunner
{
    public function __construct(
        private ScenarioCatalog $catalog,
        private ScenarioRunStore $runs,
        private ScenarioPestProcess $process,
        private ScenarioRecovery $recovery,
        private SecretRedactor $redactor,
    ) {}

    /** @param list<string> $selected @param (Closure(string): void)|null $output */
    public function run(
        string $candidate,
        string $repository,
        string $primary,
        string $lane,
        array $selected = [],
        ?Closure $output = null,
    ): ScenarioAggregate {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
            throw new InvalidArgumentException('The exact scenario candidate is invalid.');
        }
        if (! str_starts_with($repository, '/') || ! str_starts_with($primary, '/')) {
            throw new InvalidArgumentException('Scenario repository roots must be absolute.');
        }

        // Resolve and validate the complete catalog and requested selection before
        // creating run state or allowing a child process to reach Incus.
        $definitions = $this->catalog->select($candidate, $selected, $lane);
        $run = ScenarioRunId::generate();
        $startedAt = self::now();
        $this->runs->beginRun($run, $candidate, $definitions, $startedAt);
        $results = [];

        foreach ($definitions as $definition) {
            $attempt = AttemptId::generate();
            $operation = new OperationId(bin2hex(random_bytes(16)));
            $target = TopologyTarget::disposableScenario($run, $definition->id, $attempt, $definition->recipe);
            $this->runs->beginAttempt(
                $run,
                $definition,
                $attempt,
                $operation,
                $target,
                $candidate,
                self::now(),
            );

            $process = $this->process->run(
                $definition,
                $candidate,
                $run,
                $attempt,
                $operation,
                $repository,
                $primary,
            );
            if ($process->output !== '') {
                $output?->__invoke($process->output);
            }
            $result = $this->runs->result($run, $definition->id, $attempt);
            if ($result === null) {
                $result = $this->missingResult(
                    $definition,
                    $candidate,
                    $run,
                    $attempt,
                    $process->exitCode,
                    $process->output,
                );
                $this->runs->writeResult($result);
            } elseif ($process->exitCode !== 0 && $result->status === ScenarioStatus::Passed) {
                $result = $this->replaceStatus(
                    $result,
                    ScenarioStatus::InfrastructureError,
                    "Pest exited {$process->exitCode} after writing a passing result.",
                );
                $this->runs->writeResult($result);
            }
            $results[] = $result;
        }

        $aggregate = new ScenarioAggregate($candidate, $run, $results, $startedAt, self::now());
        $this->runs->writeAggregate($aggregate);

        return $aggregate;
    }

    private function missingResult(
        ScenarioDefinition $definition,
        string $candidate,
        ScenarioRunId $run,
        AttemptId $attempt,
        int $exitCode,
        string $output,
    ): ScenarioResult {
        $cleanup = $this->recovery->cleanup($run, $definition->id, $attempt);
        $diagnostic = trim($this->redactor->redact($output));
        if ($diagnostic === '') {
            $diagnostic = "Pest exited {$exitCode} without a scenario result.";
        }

        return new ScenarioResult(
            $candidate,
            $run,
            $definition->id,
            $attempt,
            $definition->lane,
            ScenarioStatus::InfrastructureError,
            ScenarioStatus::InfrastructureError,
            $definition->normalized(),
            $definition->fingerprint(),
            $definition->recipe->fingerprint(),
            [],
            [],
            null,
            [$diagnostic],
            $cleanup->toArray(),
            self::now(),
            self::now(),
        );
    }

    private function replaceStatus(ScenarioResult $result, ScenarioStatus $status, string $diagnostic): ScenarioResult
    {
        return new ScenarioResult(
            $result->candidate,
            $result->run,
            $result->scenario,
            $result->attempt,
            $result->lane,
            $result->primaryStatus,
            $status,
            $result->definition,
            $result->definitionFingerprint,
            $result->recipeFingerprint,
            $result->actions,
            $result->phaseTimings,
            $result->verification,
            [...$result->diagnostics, $diagnostic],
            $result->cleanup,
            $result->startedAt,
            self::now(),
        );
    }

    private static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }
}
