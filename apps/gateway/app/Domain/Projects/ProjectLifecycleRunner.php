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

            try {
                $timeout = $this->deadline->cap($step->timeoutSeconds + 5.0);

                if ($timeout <= 5.0) {
                    throw new ResourceOperationException(
                        errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                        message: 'The command deadline leaves no time for this step.',
                        status: 422,
                        details: ['step' => $step->name, 'outcome' => 'unconfirmed'],
                    );
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
                if ($exception instanceof ResourceOperationException) {
                    throw $exception;
                }

                $result = $exception instanceof RuntimeConvergenceException ? $exception->result : null;
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
}
