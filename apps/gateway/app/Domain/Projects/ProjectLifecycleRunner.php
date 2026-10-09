<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use Illuminate\Support\Facades\Log;
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
        private DevelopmentDeployment $deployment,
    ) {}

    public function run(Instance $instance, LifecyclePhase $phase): bool
    {
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

        $directory = $this->servedDirectory($instance, $phase, $checkout);
        $inRelease = $directory !== $checkout;
        // A default Instance's seed is its own active release. Setup that runs in a release has no
        // other seed, even when the stored seed still names an older release.
        $seeded = $phase === LifecyclePhase::Setup && ! $inRelease;
        // Synchronization writes `.env` and `.env.testing` in the checkout. Setup in a release copies
        // them in first, as a deploy does, so a migration sees the database that was just attached.
        $environmentDirectory = $phase === LifecyclePhase::Setup && $inRelease
            ? $this->environmentDirectory($instance, $checkout)
            : null;
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
                    'directory' => $directory,
                    ...($environmentDirectory === null ? [] : ['environment_directory' => $environmentDirectory]),
                    'command' => $command,
                    'environment' => [
                        'VP_HOME' => $vpHome,
                        'ORBIT_SEED_PATH' => $seeded ? ($instance->seed_path ?? '') : '',
                        'ORBIT_SEED_COMMIT' => $seeded ? ($instance->seed_commit ?? '') : '',
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
     * The directory the Instance serves. A default Instance with the development release layout
     * serves the release that `current` selects, and its checkout stays at the commit it was
     * cloned at. Steps such as migrations must see the code that runs, so they run in that release.
     *
     * Teardown runs only while the Instance is removed. When Orbit cannot read the active release
     * there, for example because a release lost its worktree entry, teardown runs in the checkout,
     * so the removal can still finish.
     */
    private function servedDirectory(Instance $instance, LifecyclePhase $phase, string $checkout): string
    {
        if (! $instance->development_release_layout) {
            return $checkout;
        }

        try {
            $release = $this->deployment->selected($instance);
        } catch (Throwable $exception) {
            if ($phase === LifecyclePhase::Teardown) {
                Log::warning('Teardown runs in the checkout because the active release is unavailable.', [
                    'instance_id' => $instance->id,
                    'error' => $exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class,
                ]);

                return $checkout;
            }

            throw new ResourceOperationException(
                errorCode: 'instance.active_release_unavailable',
                message: 'Setup did not start: Orbit could not read the active release of the Instance.',
                status: 409,
                previous: $exception,
            );
        }

        return $release->path;
    }

    /**
     * Where synchronization keeps the environment files, relative to the checkout: the Laravel
     * application directory, or the checkout root. The release has the same layout.
     */
    private function environmentDirectory(Instance $instance, string $checkout): string
    {
        $path = $instance->source_is_laravel === true ? $instance->applicationDirectory() : $checkout;

        if ($path === $checkout) {
            return '';
        }

        if (! str_starts_with($path, $checkout.'/')) {
            throw new ResourceOperationException('instance.setup_step_failed', 'The application directory is outside the checkout.', 422);
        }

        return substr($path, strlen($checkout) + 1);
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
