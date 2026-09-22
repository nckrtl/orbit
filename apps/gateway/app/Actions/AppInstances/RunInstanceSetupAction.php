<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class RunInstanceSetupAction
{
    public function __construct(private ProjectLifecycleRunner $runner) {}

    public function execute(AppInstance $instance): AppInstance
    {
        if ($instance->placedOnAppProd()) {
            throw new ResourceOperationException(
                errorCode: 'instance.setup_unavailable',
                message: 'Production Instances do not run setup steps.',
                status: 409,
            );
        }

        $this->runner->run($instance, LifecyclePhase::Setup);

        return $instance;
    }
}
