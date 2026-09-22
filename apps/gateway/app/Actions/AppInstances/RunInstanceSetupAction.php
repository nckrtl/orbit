<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class RunInstanceSetupAction
{
    public function __construct(
        private ProjectLifecycleRunner $runner,
        private AppDevSourceOperationLock $sourceLock,
        private AppInstanceEnvironmentOperationLock $environmentOperations,
    ) {}

    public function execute(AppInstance $instance): AppInstance
    {
        return $this->environmentOperations->run(
            [$instance->id],
            fn (): AppInstance => $this->sourceLock->synchronized(
                $instance->node_id,
                fn (): AppInstance => $this->runLocked($instance->refresh()),
            ),
        );
    }

    private function runLocked(AppInstance $instance): AppInstance
    {
        if ($instance->placedOnAppProd() || $instance->status !== AppInstanceState::Active) {
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

        if ($instance->failed_step === 'setup') {
            $instance->update(['failed_step' => null, 'error_code' => null]);
        }

        return $instance;
    }
}
