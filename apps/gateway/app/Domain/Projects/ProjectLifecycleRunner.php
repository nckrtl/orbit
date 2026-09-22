<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Throwable;

final readonly class ProjectLifecycleRunner
{
    public function __construct(
        private ProjectLifecycleStepStore $steps,
        private AppDevSshExecutor $ssh,
    ) {}

    public function run(AppInstance $instance, LifecyclePhase $phase): void
    {
        if ($instance->placedOnAppProd()) {
            return;
        }

        $instance->loadMissing(['app', 'node']);
        $steps = $this->steps->ordered($instance->app, $phase);

        if ($steps === []) {
            return;
        }

        $checkout = $instance->checkout_path;

        if ($checkout === '') {
            throw new ResourceOperationException(
                errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                message: 'The Instance has no checkout.',
                status: 422,
            );
        }

        foreach ($steps as $step) {
            try {
                $this->ssh->execute(
                    $instance->node,
                    new RemoteCommand(
                        arguments: [
                            'bash',
                            '-eu',
                            '-c',
                            'cd -- "$1" && exec bash -eu -c "$2"',
                            'bash',
                            $checkout,
                            $step->command,
                        ],
                        timeout: (float) $step->timeoutSeconds,
                    ),
                    $step->name,
                    $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                    (float) $step->timeoutSeconds,
                );
            } catch (Throwable $exception) {
                if ($exception instanceof ResourceOperationException) {
                    throw $exception;
                }

                throw new ResourceOperationException(
                    errorCode: $phase === LifecyclePhase::Setup ? 'instance.setup_step_failed' : 'instance.teardown_step_failed',
                    message: $phase === LifecyclePhase::Setup ? 'Setup step failed.' : 'Teardown step failed.',
                    status: 422,
                    previous: $exception,
                    details: ['step' => $step->name],
                );
            }
        }
    }
}
