<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final readonly class ProjectLifecycleRunner
{
    public function __construct(
        private ProjectLifecycleStepStore $steps,
        private AppDevSshExecutor $ssh,
        private CommandDeadline $deadline,
    ) {}

    public function run(AppInstance $instance, LifecyclePhase $phase): bool
    {
        if ($instance->placedOnAppProd()) {
            return false;
        }

        $instance->loadMissing(['app', 'node']);
        $steps = $this->steps->ordered($instance->app, $phase);

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

        foreach ($steps as $step) {
            $input = null;
            $budget = 0.0;

            try {
                $timeout = $this->deadline->cap($step->timeoutSeconds + 5.0);
                $budget = $timeout - 5.0;

                if ($budget <= 0.0) {
                    throw $this->deadlineCut($phase, $step, 0.0);
                }

                $input = ProtectedInput::fromString(json_encode([
                    'checkout' => $checkout,
                    'command' => $step->command,
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
                    throw $exception;
                }

                $result = $exception instanceof RuntimeConvergenceException ? $exception->result : null;

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
