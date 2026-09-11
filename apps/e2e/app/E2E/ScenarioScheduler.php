<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioProcessResult;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyTarget;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Schedule independent scenario Pest processes within one explicit worker limit. */
final readonly class ScenarioScheduler
{
    public function __construct(
        private ScenarioRunStore $runs,
        private ScenarioPestProcess $process,
        private ScenarioRecovery $recovery,
        private SecretRedactor $redactor,
        private ?Closure $idle = null,
        private float $terminationGraceSeconds = 120.0,
    ) {
        if ($terminationGraceSeconds < 0) {
            throw new InvalidArgumentException('The scenario termination grace period is invalid.');
        }
    }

    /**
     * @param  list<ScenarioDefinition>  $definitions
     * @param  (Closure(string): void)|null  $output
     * @return list<ScenarioResult>
     */
    public function run(
        array $definitions,
        string $candidate,
        ScenarioRunId $run,
        string $repository,
        string $primary,
        int $workers,
        ?Closure $output = null,
    ): array {
        if ($workers < 1) {
            throw new InvalidArgumentException('The scenario worker count must be a positive integer.');
        }
        if ($definitions === []) {
            throw new InvalidArgumentException('The scheduled scenario selection is invalid.');
        }

        $interruptedSignal = null;
        $restoreSignals = $this->installSignalHandlers($interruptedSignal);
        $queued = array_keys($definitions);
        $active = [];
        $results = [];

        try {
            while ($queued !== [] || $active !== []) {
                while ($interruptedSignal === null && $queued !== [] && count($active) < $workers) {
                    $index = array_shift($queued);
                    $definition = $definitions[$index];
                    $attempt = AttemptId::generate();
                    $operation = new OperationId(bin2hex(random_bytes(16)));
                    $target = TopologyTarget::disposableScenario($run, $definition->id, $attempt, $definition->recipe);
                    $startedAt = self::now();
                    $this->runs->beginAttempt(
                        $run,
                        $definition,
                        $attempt,
                        $operation,
                        $target,
                        $candidate,
                        $startedAt,
                    );

                    try {
                        $worker = $this->process->start(
                            $definition,
                            $candidate,
                            $run,
                            $attempt,
                            $operation,
                            $repository,
                            $primary,
                        );
                        $active[$index] = [
                            'definition' => $definition,
                            'attempt' => $attempt,
                            'worker' => $worker,
                            'started_at' => $startedAt,
                        ];
                    } catch (Throwable $exception) {
                        $results[$index] = $this->missingResult(
                            $definition,
                            $candidate,
                            $run,
                            $attempt,
                            70,
                            $exception->getMessage(),
                            $startedAt,
                        );
                        $this->runs->writeResult($results[$index]);
                    }
                }

                if ($interruptedSignal !== null) {
                    $this->interruptActive(
                        $active,
                        $results,
                        $candidate,
                        $run,
                        $interruptedSignal,
                        $output,
                    );
                    foreach ($queued as $index) {
                        $definition = $definitions[$index];
                        $attempt = AttemptId::generate();
                        $operation = new OperationId(bin2hex(random_bytes(16)));
                        $target = TopologyTarget::disposableScenario(
                            $run,
                            $definition->id,
                            $attempt,
                            $definition->recipe,
                        );
                        $startedAt = self::now();
                        $this->runs->beginAttempt(
                            $run,
                            $definition,
                            $attempt,
                            $operation,
                            $target,
                            $candidate,
                            $startedAt,
                        );
                        $results[$index] = $this->missingResult(
                            $definition,
                            $candidate,
                            $run,
                            $attempt,
                            128 + $interruptedSignal,
                            "Scenario was not started because the run was interrupted by signal {$interruptedSignal}.",
                            $startedAt,
                        );
                        $this->runs->writeResult($results[$index]);
                    }
                    $queued = [];

                    break;
                }

                if (! $this->collectCompleted($active, $results, $candidate, $run, $output)) {
                    $this->idle();
                }
            }
        } finally {
            $restoreSignals();
        }

        ksort($results, SORT_NUMERIC);

        return array_values($results);
    }

    /**
     * @param  array<int, array{definition:ScenarioDefinition,attempt:AttemptId,worker:ScenarioWorkerProcess,started_at:string}>  $active
     * @param  array<int, ScenarioResult>  $results
     * @param  (Closure(string): void)|null  $output
     */
    private function collectCompleted(
        array &$active,
        array &$results,
        string $candidate,
        ScenarioRunId $run,
        ?Closure $output,
    ): bool {
        $completed = false;
        foreach (array_keys($active) as $index) {
            $context = $active[$index];
            try {
                $process = $context['worker']->poll();
            } catch (Throwable $exception) {
                $process = new ScenarioProcessResult(70, $exception->getMessage());
            }
            if ($process === null) {
                continue;
            }

            if ($process->output !== '') {
                $output?->__invoke($process->output);
            }
            $results[$index] = $this->resultForProcess(
                $context['definition'],
                $candidate,
                $run,
                $context['attempt'],
                $process,
                $context['started_at'],
            );
            unset($active[$index]);
            $completed = true;
        }

        return $completed;
    }

    /**
     * @param  array<int, array{definition:ScenarioDefinition,attempt:AttemptId,worker:ScenarioWorkerProcess,started_at:string}>  $active
     * @param  array<int, ScenarioResult>  $results
     * @param  (Closure(string): void)|null  $output
     */
    private function interruptActive(
        array &$active,
        array &$results,
        string $candidate,
        ScenarioRunId $run,
        int $signal,
        ?Closure $output,
    ): void {
        foreach ($active as $context) {
            try {
                $context['worker']->signal($signal);
            } catch (Throwable) {
                // A worker can finish between polling and signaling. Completion below owns the result.
            }
        }

        $deadline = microtime(true) + $this->terminationGraceSeconds;
        while ($active !== [] && microtime(true) < $deadline) {
            if (! $this->collectCompleted($active, $results, $candidate, $run, $output)) {
                $this->idle();
            }
        }

        foreach (array_keys($active) as $index) {
            $context = $active[$index];
            try {
                $process = $context['worker']->forceStop();
            } catch (Throwable $exception) {
                $process = new ScenarioProcessResult(70, $exception->getMessage());
            }
            if ($process->output !== '') {
                $output?->__invoke($process->output);
            }
            $results[$index] = $this->resultForProcess(
                $context['definition'],
                $candidate,
                $run,
                $context['attempt'],
                $process,
                $context['started_at'],
            );
            unset($active[$index]);
        }
    }

    private function resultForProcess(
        ScenarioDefinition $definition,
        string $candidate,
        ScenarioRunId $run,
        AttemptId $attempt,
        ScenarioProcessResult $process,
        string $startedAt,
    ): ScenarioResult {
        try {
            $result = $this->runs->result($run, $definition->id, $attempt);
        } catch (Throwable $exception) {
            $result = null;
            $process = new ScenarioProcessResult(
                $process->exitCode === 0 ? 70 : $process->exitCode,
                trim($process->output."\n".$exception->getMessage()),
            );
        }

        if ($result === null) {
            $result = $this->missingResult(
                $definition,
                $candidate,
                $run,
                $attempt,
                $process->exitCode,
                $process->output,
                $startedAt,
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

        return $result;
    }

    private function missingResult(
        ScenarioDefinition $definition,
        string $candidate,
        ScenarioRunId $run,
        AttemptId $attempt,
        int $exitCode,
        string $output,
        string $startedAt,
    ): ScenarioResult {
        $diagnostics = [];
        $diagnostic = trim($this->redactor->redact($output));
        if ($diagnostic === '') {
            $diagnostic = "Pest exited {$exitCode} without a scenario result.";
        }
        $diagnostics[] = $diagnostic;

        try {
            $cleanup = $this->recovery->cleanup($run, $definition->id, $attempt);
        } catch (Throwable $exception) {
            $target = TopologyTarget::disposableScenario($run, $definition->id, $attempt, $definition->recipe);
            $diagnostics[] = $this->redactor->redact($exception->getMessage());
            $cleanup = new ColdTopologyCleanupResult(
                [],
                [],
                [$this->redactor->redact($exception->getMessage())],
                [
                    ...array_map($target->instance(...), $definition->recipe->nodeKeys()),
                    $target->network(),
                ],
                $this->runs->recoveryCommand($run, $definition->id, $attempt),
            );
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
            $diagnostics,
            $cleanup->toArray(),
            $startedAt,
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

    /** @param int|null $interruptedSignal @return Closure(): void */
    private function installSignalHandlers(?int &$interruptedSignal): Closure
    {
        if (
            ! function_exists('pcntl_async_signals')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
        ) {
            throw new RuntimeException('Scenario scheduling requires the PCNTL extension.');
        }

        $async = pcntl_async_signals();
        $handlers = [];
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $received) use (&$interruptedSignal): void {
                $interruptedSignal ??= $received;
            });
        }

        return static function () use ($async, $handlers): void {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($async);
        };
    }

    private function idle(): void
    {
        if ($this->idle !== null) {
            ($this->idle)();

            return;
        }

        usleep(10_000);
    }

    private static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }
}
