<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final readonly class RunInstanceSetupAction
{
    public function __construct(
        private ProjectLifecycleRunner $runner,
        private AppDevSourceOperationLock $sourceLock,
        private InstanceEnvironmentOperationLock $environmentOperations,
    ) {}

    public function execute(Instance $instance): Instance
    {
        return $this->environmentOperations->run(
            [$instance->id],
            fn (): Instance => $this->sourceLock->synchronized(
                $instance->node_id,
                fn (): Instance => $this->runLocked($instance->refresh()),
            ),
        );
    }

    private function runLocked(Instance $instance): Instance
    {
        if ($instance->placedOnAppProd() || $instance->status !== InstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'instance.setup_unavailable',
                message: 'Setup requires an active development Instance.',
                status: 409,
            );
        }

        try {
            $this->runner->run($instance, LifecyclePhase::Setup);
        } catch (ResourceOperationException $exception) {
            $instance->update(['failed_step' => 'setup', 'error_code' => $exception->errorCode]);

            throw $exception;
        }

        // A completed setup also completes an unfinished create, so a create retry no longer resumes it.
        if ($instance->failed_step === 'setup' || $instance->first_setup_pending) {
            $instance->update(['failed_step' => null, 'error_code' => null, 'first_setup_pending' => false]);
        }

        return $instance;
    }
}
