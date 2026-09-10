<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\State\ScenarioRunStore;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioResult;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\ScenarioStatus;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Run one disposable scenario from an exact promoted snapshot generation. */
final readonly class SnapshotScenarioRunner
{
    private const string ISOLATION_MARKER = '/var/lib/orbit-e2e/snapshot-scenario-isolation';

    public function __construct(
        private ScenarioCatalog $catalog,
        private ScenarioRunStore $runs,
        private PromotedTopologySnapshotResolver $snapshots,
        private IssueTopologyConstructor $constructor,
        private IncusHost $host,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private ScenarioRecovery $recovery,
        private OperationId $operation,
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

        $definition = $this->catalog->select($candidate, [$scenario->value], 'snapshot')[0];
        if ($definition->lane !== 'snapshot') {
            throw new InvalidArgumentException('The scenario process did not select a snapshot definition.');
        }
        $state = $this->runs->attempt($run, $scenario, $attempt);
        if (
            ($state['candidate_sha'] ?? null) !== $candidate
            || ($state['operation_id'] ?? null) !== $operation->value
            || ($state['definition_fingerprint'] ?? null) !== $definition->fingerprint()
            || $this->operation->value !== $operation->value
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
        $prepared = false;
        $construction = null;

        $inputs = [
            'candidate_sha' => $candidate,
            'source_generation' => null,
            'extension' => $definition->extension?->value,
            'construction' => null,
            'candidate_sync' => null,
        ];
        $this->runs->recordConstructionInputs($run, $definition->id, $attempt, $inputs);

        $metadata = [
            'user.orbit.e2e.issue' => 'SCN-1',
            'user.orbit.e2e.run' => $run->value,
            'user.orbit.e2e.scenario' => $definition->id->value,
            'user.orbit.e2e.attempt' => $attempt->value,
            'user.orbit.e2e.operation' => $operation->value,
            'user.orbit.e2e.recipe' => $definition->recipe->id,
        ];

        try {
            $generation = $this->phase(
                $run,
                $definition,
                $attempt,
                'resolve-generation',
                fn () => $this->snapshots->resolve($repository),
                $phaseTimings,
            );
            $inputs['source_generation'] = $generation->toArray();
            $this->runs->recordConstructionInputs($run, $definition->id, $attempt, $inputs);

            $construction = $this->phase(
                $run,
                $definition,
                $attempt,
                'construct',
                fn (): TopologyConstructionInputs => $this->constructor->construct(
                    $target,
                    $generation,
                    $metadata,
                    extension: $definition->extension,
                ),
                $phaseTimings,
            );
            $inputs['construction'] = $construction->toArray();
            $this->runs->recordConstructionInputs($run, $definition->id, $attempt, $inputs);

            $instances = array_map($target->instance(...), $definition->recipe->nodeKeys());
            $this->phase(
                $run,
                $definition,
                $attempt,
                'start-instances',
                fn () => $this->host->startAll($instances),
                $phaseTimings,
            );
            $this->phase(
                $run,
                $definition,
                $attempt,
                'prepare-host-state',
                fn () => $this->host->prepareClonedHostStates($instances),
                $phaseTimings,
            );
            $sync = $this->phase(
                $run,
                $definition,
                $attempt,
                'synchronize-candidate',
                fn () => $this->synchronizer->syncCommit($target, $repository, $candidate),
                $phaseTimings,
            );
            $candidateTree = new GitRepository($repository)->tree($candidate);
            if ($sync->candidateSha !== $candidate || $sync->candidateTree !== $candidateTree) {
                throw new RuntimeException('The snapshot scenario candidate sync changed the exact candidate identity.');
            }
            $inputs['candidate_sync'] = $sync->toArray();
            $this->runs->recordConstructionInputs($run, $definition->id, $attempt, $inputs);
            $source = new SourceState($candidate, $candidate, operationId: $sync->operationId);
            $this->phase(
                $run,
                $definition,
                $attempt,
                'candidate-identity',
                fn () => $this->synchronizer->probeCheckoutIdentity($target, $candidate, $candidateTree),
                $phaseTimings,
            );
            $this->phase(
                $run,
                $definition,
                $attempt,
                'converge',
                fn () => $this->converger->converge($target, $source, $generation->laravel),
                $phaseTimings,
            );
            $this->phase(
                $run,
                $definition,
                $attempt,
                'converged-candidate-identity',
                fn () => $this->synchronizer->probeCheckoutIdentity($target, $candidate, $candidateTree),
                $phaseTimings,
            );
            $readiness = $this->phase(
                $run,
                $definition,
                $attempt,
                'readiness',
                fn () => $this->verifier->verify(
                    $target,
                    VerificationMode::Readiness,
                    $source,
                    $definition->expectedEndState,
                    $definition->recipe->assignments(),
                ),
                $phaseTimings,
            );
            $verification = $readiness->toArray();
            if (! $readiness->passed) {
                throw new RuntimeException('Snapshot scenario readiness verification failed.'.$readiness->failedSummary());
            }
            $this->recordPhaseActions($definition, 'setup', true, 'exact promoted snapshot prepared', $actions);
            $prepared = true;
        } catch (Throwable $exception) {
            $diagnostic = $this->redactor->redact($exception->getMessage());
            $diagnostics[] = $diagnostic;
            $this->recordPhaseActions($definition, 'setup', false, $diagnostic, $actions);
        }

        if ($prepared) {
            try {
                foreach ($this->actionsFor($definition, 'exercise') as $action) {
                    $started = microtime(true);
                    try {
                        $evidence = $this->exercise($definition, $target, $construction, $operation, $action);
                        $actions[] = $this->action($action, $started, microtime(true), true, $evidence);
                    } catch (Throwable $exception) {
                        $diagnostic = $this->redactor->redact($exception->getMessage());
                        $diagnostics[] = $diagnostic;
                        $actions[] = $this->action($action, $started, microtime(true), false, $diagnostic);
                        if ($action->required) {
                            throw $exception;
                        }
                    }
                }
            } catch (Throwable $exception) {
                $diagnostic = $this->redactor->redact($exception->getMessage());
                if (! in_array($diagnostic, $diagnostics, true)) {
                    $diagnostics[] = $diagnostic;
                }
                $primary = ScenarioStatus::Failed;
            }

            if ($primary !== ScenarioStatus::Failed) {
                try {
                    $source = new SourceState($candidate, $candidate, operationId: $operation->value);
                    $final = $this->phase(
                        $run,
                        $definition,
                        $attempt,
                        'verify',
                        fn () => $this->verifier->verify(
                            $target,
                            VerificationMode::Proof,
                            $source,
                            $definition->expectedEndState,
                            $definition->recipe->assignments(),
                        ),
                        $phaseTimings,
                    );
                    $verification = $final->toArray();
                    if (! $final->passed) {
                        throw new RuntimeException('Snapshot scenario final verification failed.'.$final->failedSummary());
                    }
                    $this->recordPhaseActions($definition, 'assertion', true, 'declared final topology verified', $actions);
                    $primary = ScenarioStatus::Passed;
                } catch (Throwable $exception) {
                    $diagnostic = $this->redactor->redact($exception->getMessage());
                    if (! in_array($diagnostic, $diagnostics, true)) {
                        $diagnostics[] = $diagnostic;
                    }
                    $this->recordPhaseActions($definition, 'assertion', false, $diagnostic, $actions);
                    $primary = ScenarioStatus::Failed;
                }
            }
        }

        $cleanupStarted = microtime(true);
        $cleanup = $this->recovery->cleanup($run, $definition->id, $attempt);
        $cleanupFinished = microtime(true);
        $cleanupTiming = [
            'started_at' => self::timestamp($cleanupStarted),
            'finished_at' => self::timestamp($cleanupFinished),
            'duration_ms' => (int) round(($cleanupFinished - $cleanupStarted) * 1000),
            'passed' => $cleanup->successful(),
            'error' => $cleanup->successful() ? null : implode('; ', $cleanup->refused),
        ];
        $phaseTimings['cleanup'] = $cleanupTiming;
        $this->runs->recordPhase($run, $definition->id, $attempt, 'cleanup', $cleanupTiming);
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

    private function exercise(
        ScenarioDefinition $definition,
        TopologyTarget $target,
        ?TopologyConstructionInputs $construction,
        OperationId $operation,
        ScenarioAction $action,
    ): string {
        $instance = $target->instance('app-dev');
        $command = ['true'];
        $evidence = 'bounded app-dev exercise completed';
        if ($action->name === 'isolation') {
            $command = [
                'sudo',
                'sh',
                '-c',
                'test ! -e "$1" && install -o 1000 -g 1000 -m 0600 /dev/null "$1"',
                'orbit-e2e',
                self::ISOLATION_MARKER,
            ];
            $evidence = 'fresh clone did not contain the prior attempt marker';
        } elseif ($action->name === 'extension') {
            $this->assertExtension($definition, $target, $construction, $operation);
            $instance = $target->instance('app-prod-2');
            $evidence = 'declared extension identity, capacity, and image fingerprint recorded';
        }

        $result = $this->host->exec($instance, GuestCommand::asProofAction($command, $action->deadlineSeconds));
        if (! $result->successful()) {
            throw new RuntimeException(
                "Snapshot scenario exercise [{$action->name}] failed with exit code {$result->exitCode}.",
            );
        }

        return $evidence;
    }

    private function assertExtension(
        ScenarioDefinition $definition,
        TopologyTarget $target,
        ?TopologyConstructionInputs $construction,
        OperationId $operation,
    ): void {
        if (
            $definition->extension === null
            || $construction === null
            || $construction->extension !== $definition->extension
            || $construction->imageAlias === null
            || $construction->imageFingerprint === null
            || $construction->slot < 1
            || ($construction->nodes['app-prod-2']['instance'] ?? null) !== $target->instance('app-prod-2')
        ) {
            throw new RuntimeException('The snapshot scenario extension construction record is incomplete.');
        }
        $instance = $this->host->instances([$target->instance('app-prod-2')])[$target->instance('app-prod-2')] ?? null;
        if (
            $instance === null
            || ($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($instance->metadata['user.orbit.e2e.issue'] ?? null) !== 'SCN-1'
            || ($instance->metadata['user.orbit.e2e.run'] ?? null) !== $target->requireScenarioRun()->value
            || ($instance->metadata['user.orbit.e2e.scenario'] ?? null) !== $definition->id->value
            || ($instance->metadata['user.orbit.e2e.attempt'] ?? null) !== $target->requireAttempt()->value
            || ($instance->metadata['user.orbit.e2e.operation'] ?? null) !== $operation->value
        ) {
            throw new RuntimeException('The snapshot scenario extension physical identity is invalid.');
        }
    }

    /** @param list<array<string, mixed>> $actions */
    private function recordPhaseActions(
        ScenarioDefinition $definition,
        string $phase,
        bool $passed,
        string $evidence,
        array &$actions,
    ): void {
        foreach ($this->actionsFor($definition, $phase) as $action) {
            $now = microtime(true);
            $actions[] = $this->action($action, $now, $now, $passed, $evidence);
        }
    }

    /** @return list<ScenarioAction> */
    private function actionsFor(ScenarioDefinition $definition, string $phase): array
    {
        return array_values(array_filter(
            $definition->actions,
            static fn (ScenarioAction $action): bool => $action->phase === $phase,
        ));
    }

    /** @return array<string, mixed> */
    private function action(
        ScenarioAction $action,
        float $started,
        float $finished,
        bool $passed,
        string $evidence,
    ): array {
        return [
            'phase' => $action->phase,
            'name' => $action->name,
            'required' => $action->required,
            'started_at' => self::timestamp($started),
            'finished_at' => self::timestamp($finished),
            'duration_ms' => (int) round(($finished - $started) * 1000),
            'outcome' => $passed ? 'passed' : 'failed',
            'evidence' => $evidence,
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  array<string, array<string, mixed>>  $phaseTimings
     * @return T
     */
    private function phase(
        ScenarioRunId $run,
        ScenarioDefinition $definition,
        AttemptId $attempt,
        string $name,
        Closure $callback,
        array &$phaseTimings,
    ): mixed {
        $started = microtime(true);

        try {
            $result = $callback();
            $timing = $this->timing($started, microtime(true), true, null);
            $phaseTimings[$name] = $timing;
            $this->runs->recordPhase($run, $definition->id, $attempt, $name, $timing);

            return $result;
        } catch (Throwable $exception) {
            $timing = $this->timing(
                $started,
                microtime(true),
                false,
                $this->redactor->redact($exception->getMessage()),
            );
            $phaseTimings[$name] = $timing;
            $this->runs->recordPhase($run, $definition->id, $attempt, $name, $timing);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function timing(float $started, float $finished, bool $passed, ?string $error): array
    {
        return [
            'started_at' => self::timestamp($started),
            'finished_at' => self::timestamp($finished),
            'duration_ms' => (int) round(($finished - $started) * 1000),
            'passed' => $passed,
            'error' => $error,
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
