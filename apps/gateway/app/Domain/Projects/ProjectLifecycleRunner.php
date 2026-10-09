<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ProjectSandboxRuntimeGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class ProjectLifecycleRunner
{
    public function __construct(
        private ProjectLifecycleStepStore $steps,
        private DevelopmentSshExecutor $ssh,
        private CommandDeadline $deadline,
        private VpToolManager $vp,
        private TiaBaselineSetup $tia,
    ) {}

    public function run(Instance $instance, LifecyclePhase $phase): bool
    {
        ProjectSandboxRuntimeGuard::assertRuntime($instance);
        if ($instance->placedOnAppProd()) {
            return false;
        }

        $instance->loadMissing(['project', 'node']);
        $steps = $this->steps->ordered($instance->project, $phase);

        if ($steps === []) {
            return false;
        }

        $checkout = $instance->checkout_path;

        if ($checkout === '') {
            throw new ResourceOperationException(
                errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                message: 'The Instance has no checkout.',
                status: 422,
            );
        }

        $program = file_get_contents(resource_path('instances/lifecycle.py'));

        if (! is_string($program) || $program === '') {
            throw new ResourceOperationException('instance.setup_unavailable', 'The lifecycle runner is unavailable.', 503);
        }

        $vpHome = null;

        foreach ($steps as $step) {
            $input = null;
            $budget = 0.0;

            try {
                $vpHome ??= dirname($this->vp->existingBinary($instance->node), 2);
                $timeout = $this->deadline->cap($step->timeoutSeconds + 5.0);
                $budget = $timeout - 5.0;

                if ($budget <= 0.0) {
                    throw $this->deadlineCut($phase, $step, 0.0);
                }

                $command = $step->command;
                if ($command === LifecycleStep::RestoreTiaBaseline) {
                    if ($phase !== LifecyclePhase::Setup) {
                        throw new ResourceOperationException('instance.teardown_step_failed', 'TIA baseline restore is a setup-only operation.', 422, details: ['step' => $step->name]);
                    }
                    $started = microtime(true);
                    $command = $this->tia->command($instance->project, $budget);
                    $timeout = $this->deadline->cap(max(0.0, $timeout - (microtime(true) - $started)));
                    $budget = $timeout - 5.0;
                    if ($budget <= 0.0) {
                        throw $this->deadlineCut($phase, $step, 0.0);
                    }
                }

                $input = ProtectedInput::fromString(json_encode([
                    'checkout' => $checkout,
                    'command' => $command,
                    'environment' => [
                        'VP_HOME' => $vpHome,
                        'ORBIT_SEED_PATH' => $phase === LifecyclePhase::Setup ? ($instance->seed_path ?? '') : '',
                        'ORBIT_SEED_COMMIT' => $phase === LifecyclePhase::Setup ? ($instance->seed_commit ?? '') : '',
                    ],
                    'timeout' => $timeout - 5.0,
                ], JSON_THROW_ON_ERROR));
                $this->ssh->execute(
                    $instance->node,
                    new RemoteCommand(
                        arguments: ['python3', '-c', $program],
                        protectedInput: $input,
                        maxOutputBytes: 1024,
                        timeout: $timeout,
                    ),
                    $step->name,
                    $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                    $timeout,
                );
            } catch (Throwable $exception) {
                if ($exception instanceof ResourceOperationException && $exception->errorCode === 'command.deadline_exceeded' && ($exception->details['outcome'] ?? null) !== 'deadline') {
                    throw $this->deadlineCut($phase, $step, $budget, $exception);
                }

                if ($exception instanceof ResourceOperationException) {
                    if (str_starts_with($exception->errorCode, 'instance.tia_baseline_')) {
                        throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), $exception->status, details: ['step' => $step->name]);
                    }
                    throw $exception;
                }

                $result = $exception instanceof RuntimeConvergenceException ? $exception->result : null;

                if ($result?->exitCode === 75) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.lifecycle_busy',
                        message: 'The Instance is busy with another lifecycle operation.',
                        status: 409,
                        previous: $exception,
                        details: ['step' => $step->name, 'outcome' => 'busy'],
                    );
                }

                if ($result !== null && in_array($result->exitCode, [126, 127], true)) {
                    $label = $phase === LifecyclePhase::Setup ? 'Setup' : 'Teardown';

                    throw new ResourceOperationException(
                        errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_unavailable' : 'instance.teardown_step_unavailable',
                        message: "{$label} step [{$step->name}] is unavailable on node [{$instance->node->name}]: the command was not found or is not executable (exit {$result->exitCode}).",
                        status: 422,
                        previous: $exception,
                        details: ['step' => $step->name, 'outcome' => 'missing'],
                    );
                }

                // The step ran out of the time the request had left, not out of its own timeout.
                $timedOut = $result?->exitCode === 124
                    || $exception instanceof ProcessTimedOutException
                    || $exception->getPrevious() instanceof ProcessTimedOutException;

                if ($timedOut && $budget < $step->timeoutSeconds) {
                    throw $this->deadlineCut($phase, $step, $budget, $exception);
                }

                $confirmed = $result !== null && in_array($result->exitCode, [1, 124], true);

                throw new ResourceOperationException(
                    errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                    message: $phase === LifecyclePhase::Setup ? 'Setup step failed.' : 'Teardown step failed.',
                    status: 422,
                    details: ['step' => $step->name, 'outcome' => $confirmed ? 'failed' : 'unconfirmed'],
                );
            } finally {
                $input?->close();
            }
        }

        return true;
    }

    /**
     * A step the request deadline stopped, before or during its run, rather than a step that failed.
     * It marks the deadline as reached, so the cleanup that follows gets the reserve.
     */
    private function deadlineCut(LifecyclePhase $phase, LifecycleStep $step, float $budget, ?Throwable $previous = null): ResourceOperationException
    {
        $label = $phase === LifecyclePhase::Setup ? 'Setup' : 'Teardown';
        $seconds = (int) floor(max(0.0, $budget));
        $message = $seconds === 0
            ? "{$label} step [{$step->name}] did not start: the request deadline has no time left for it."
            : "{$label} step [{$step->name}] was stopped by the request deadline after {$seconds} seconds, before its own {$step->timeoutSeconds}-second timeout.";

        return new ResourceOperationException(
            errorCode: 'command.deadline_exceeded',
            message: $message.' Lower the list\'s step timeouts so the whole list fits one request.',
            status: 504,
            previous: $this->deadline->exceeded($previous),
            details: ['step' => $step->name, 'outcome' => 'deadline'],
        );
    }
}
