<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ScenarioColdExecutor
{
    public function __construct(
        private ScenarioCatalog $catalog,
        private ScenarioRunStore $runs,
        private ColdTopologyConstructor $constructor,
        private IncusHost $host,
        private LaravelReleaseResolver $laravel,
        private PreparedStateFingerprint $fingerprints,
        private TopologyVerifier $verifier,
        private SecretRedactor $redactor,
    ) {}

    public function executeFromEnvironment(string $expectedScenario): ScenarioResult
    {
        $candidate = $this->environment('ORBIT_SCENARIO_CANDIDATE_SHA');
        $repository = $this->environment('ORBIT_SCENARIO_REPOSITORY');
        $run = new ScenarioRunId($this->environment('ORBIT_SCENARIO_RUN_ID'));
        $scenario = new ScenarioId($this->environment('ORBIT_SCENARIO_ID'));
        $attempt = new AttemptId($this->environment('ORBIT_SCENARIO_ATTEMPT_ID'));
        $operation = new OperationId($this->environment('ORBIT_SCENARIO_OPERATION_ID'));
        if ($scenario->value !== $expectedScenario || preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) {
            throw new InvalidArgumentException('The scenario process identity is invalid.');
        }
        if (! str_starts_with($repository, '/')) {
            throw new InvalidArgumentException('The scenario repository path must be absolute.');
        }

        $definition = $this->catalog->select($candidate, [$scenario->value])[0];
        $state = $this->runs->attempt($run, $scenario, $attempt);
        if (
            ($state['candidate_sha'] ?? null) !== $candidate
            || ($state['operation_id'] ?? null) !== $operation->value
            || ($state['definition_fingerprint'] ?? null) !== $definition->fingerprint()
        ) {
            throw new InvalidArgumentException('The scenario attempt does not match its definition and inputs.');
        }

        return $this->execute($definition, $candidate, $repository, $run, $attempt, $operation);
    }

    private function execute(
        ScenarioDefinition $definition,
        string $candidate,
        string $repository,
        ScenarioRunId $run,
        AttemptId $attempt,
        OperationId $operation,
    ): ScenarioResult {
        $startedAt = self::now();
        $target = TopologyTarget::disposableScenario($run, $definition->id, $attempt, $definition->recipe);
        $phaseTimings = [];
        $actions = [];
        $diagnostics = [];
        $verification = null;
        $primary = ScenarioStatus::InfrastructureError;
        $cleanup = null;
        $source = null;

        $this->installSignalHandlers();
        $observer = function (string $name, float $started, float $finished, bool $passed, ?string $error) use (
            $run,
            $definition,
            $attempt,
            &$phaseTimings,
        ): void {
            $timing = [
                'started_at' => self::timestamp($started),
                'finished_at' => self::timestamp($finished),
                'duration_ms' => (int) round(($finished - $started) * 1000),
                'passed' => $passed,
                'error' => $error === null ? null : $this->redactor->redact($error),
            ];
            $phaseTimings[$name] = $timing;
            $this->runs->recordPhase($run, $definition->id, $attempt, $name, $timing);
        };

        try {
            $release = $this->laravel->resolve('>=13.0.0');
            $prepared = $this->fingerprints->forCommit($candidate, $release);
            $image = $prepared->manifest['base_image_alias'] ?? null;
            if ($image !== TopologyRecipe::BASE_IMAGE) {
                throw new RuntimeException('The faithful cold flow requires the unchanged Ubuntu 26.04 runtime base alias.');
            }
            $sourceSha = $definition->expectsConstructionFailure ? str_repeat('0', 40) : $candidate;
            $imageFingerprints = [$image => $this->host->imageFingerprint($image)];
            $metadata = [
                'user.orbit.e2e.issue' => 'SCN-1',
                'user.orbit.e2e.run' => $run->value,
                'user.orbit.e2e.scenario' => $definition->id->value,
                'user.orbit.e2e.attempt' => $attempt->value,
                'user.orbit.e2e.operation' => $operation->value,
                'user.orbit.e2e.recipe' => $definition->recipe->id,
            ];
            $this->runs->recordConstructionInputs($run, $definition->id, $attempt, [
                'candidate_sha' => $candidate,
                'source_sha' => $sourceSha,
                'source_worktree' => $repository,
                'run_id' => $run->value,
                'scenario_id' => $definition->id->value,
                'attempt_id' => $attempt->value,
                'operation_id' => $operation->value,
                'recipe' => $definition->recipe->toArray(),
                'recipe_fingerprint' => $definition->recipe->fingerprint(),
                'image_fingerprints' => $imageFingerprints,
                'laravel' => ['tag' => $release->tag, 'commit' => $release->commit],
                'metadata' => $metadata,
            ]);
            $actionStarted = microtime(true);

            try {
                $this->armDeadline($this->deadline(
                    $definition,
                    $definition->expectsConstructionFailure ? 'injected-source-failure' : 'construct',
                ));
                $source = $this->constructor->construct(new ColdTopologyPlan(
                    $target,
                    $repository,
                    $sourceSha,
                    $imageFingerprints,
                    $release,
                    $operation,
                    $metadata,
                ), $observer);
                $this->cancelDeadline();
                $constructionPassed = ! $definition->expectsConstructionFailure;
                $actions[] = $this->action(
                    $definition->expectsConstructionFailure ? 'injected-source-failure' : 'construct',
                    $definition->expectsConstructionFailure ? 'exercise' : 'setup',
                    $actionStarted,
                    microtime(true),
                    $constructionPassed,
                    $constructionPassed ? 'exact cold inventory constructed' : 'construction unexpectedly succeeded',
                );
                if ($definition->expectsConstructionFailure) {
                    $primary = ScenarioStatus::Failed;
                }
            } catch (Throwable $exception) {
                $this->cancelDeadline();
                if (
                    $definition->expectsConstructionFailure
                    && $exception instanceof InvalidArgumentException
                    && $exception->getMessage() === 'The Git command failed.'
                ) {
                    $actions[] = $this->action(
                        'injected-source-failure',
                        'exercise',
                        $actionStarted,
                        microtime(true),
                        true,
                        'required source synchronization failed and stopped the flow',
                    );
                    $primary = ScenarioStatus::Passed;
                } else {
                    $diagnostics[] = $this->redactor->redact($exception->getMessage());
                    $actions[] = $this->action(
                        'construct',
                        'setup',
                        $actionStarted,
                        microtime(true),
                        false,
                        $this->redactor->redact($exception->getMessage()),
                    );
                    $primary = ScenarioStatus::InfrastructureError;
                }
            }

            if ($source !== null && ! $definition->expectsConstructionFailure) {
                $verificationStarted = microtime(true);
                $this->armDeadline($this->deadline($definition, 'verify'));
                try {
                    $report = $this->verifier->verify(
                        $target,
                        VerificationMode::Proof,
                        $source,
                        $definition->expectedEndState,
                    );
                } finally {
                    $this->cancelDeadline();
                }
                $verification = $report->toArray();
                $actions[] = $this->action(
                    'verify',
                    'assertion',
                    $verificationStarted,
                    microtime(true),
                    $report->passed,
                    $report->passed ? 'declared final topology verified' : $report->failedSummary(),
                );
                $primary = $report->passed ? ScenarioStatus::Passed : ScenarioStatus::Failed;
            }
        } catch (Throwable $exception) {
            $diagnostics[] = $this->redactor->redact($exception->getMessage());
            $primary = ScenarioStatus::InfrastructureError;
        } finally {
            $cleanupStarted = microtime(true);
            $cleanup = $this->constructor->cleanup($target, $operation);
            $cleanupTiming = [
                'started_at' => self::timestamp($cleanupStarted),
                'finished_at' => self::timestamp(microtime(true)),
                'duration_ms' => (int) round((microtime(true) - $cleanupStarted) * 1000),
                'passed' => $cleanup->successful(),
                'error' => $cleanup->successful() ? null : implode('; ', $cleanup->refused),
            ];
            $phaseTimings['cleanup'] = $cleanupTiming;
            $this->runs->recordPhase($run, $definition->id, $attempt, 'cleanup', $cleanupTiming);
        }

        $cleanup = new ColdTopologyCleanupResult(
            $cleanup->removed,
            $cleanup->absent,
            $cleanup->refused,
            $cleanup->remaining,
            $this->runs->recoveryCommand($run, $definition->id, $attempt),
        );
        $status = $cleanup->successful() ? $primary : ScenarioStatus::InfrastructureError;
        $result = new ScenarioResult(
            $candidate,
            $run,
            $definition->id,
            $attempt,
            $definition->lane,
            $primary,
            $status,
            $definition->normalized(),
            $definition->fingerprint(),
            $definition->recipe->fingerprint(),
            $actions,
            $phaseTimings,
            $verification,
            $diagnostics,
            $cleanup->toArray(),
            $startedAt,
            self::now(),
        );
        $this->runs->writeResult($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private function action(
        string $name,
        string $phase,
        float $started,
        float $finished,
        bool $passed,
        string $evidence,
    ): array {
        return [
            'phase' => $phase,
            'name' => $name,
            'required' => true,
            'started_at' => self::timestamp($started),
            'finished_at' => self::timestamp($finished),
            'duration_ms' => (int) round(($finished - $started) * 1000),
            'outcome' => $passed ? 'passed' : 'failed',
            'evidence' => $evidence,
        ];
    }

    private function environment(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Scenario environment [{$name}] is absent.");
        }

        return $value;
    }

    private function installSignalHandlers(): void
    {
        if (
            ! function_exists('pcntl_async_signals')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_alarm')
        ) {
            throw new RuntimeException('Scenario action deadlines require the PCNTL extension.');
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function (int $received): never {
                throw new RuntimeException("Scenario construction was interrupted by signal {$received}.");
            });
        }
        pcntl_signal(SIGALRM, static function (): never {
            throw new RuntimeException('The scenario action deadline expired.');
        });
    }

    private function deadline(ScenarioDefinition $definition, string $name): int
    {
        foreach ($definition->actions as $action) {
            if ($action->name === $name) {
                return $action->deadlineSeconds;
            }
        }

        throw new InvalidArgumentException("Scenario action [{$name}] has no deadline.");
    }

    private function armDeadline(int $seconds): void
    {
        pcntl_alarm($seconds);
    }

    private function cancelDeadline(): void
    {
        pcntl_alarm(0);
    }

    private static function now(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    private static function timestamp(float $seconds): string
    {
        $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds), new DateTimeZone('UTC'));

        return ($time ?: new DateTimeImmutable('@'.(string) (int) $seconds))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.uP');
    }
}
