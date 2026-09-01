<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Models\AppInstance;

final readonly class RemoveAppInstanceAction
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentAppInstanceSourceLifecycle $source,
    ) {}

    public function execute(AppInstance $appInstance, bool $discardSource): AppInstance
    {
        return $this->sourceLock->synchronized(
            $appInstance->node_id,
            function () use ($appInstance, $discardSource): AppInstance {
                $snapshot = $appInstance->refresh()->load(['app', 'node', 'cluster']);
                $this->source->remove($snapshot, $discardSource);
                $snapshot->delete();

                return $snapshot;
            },
        );
    }
}
