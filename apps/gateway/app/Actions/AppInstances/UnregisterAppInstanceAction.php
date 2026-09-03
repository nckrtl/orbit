<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class UnregisterAppInstanceAction
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
    ) {}

    public function execute(AppInstance $appInstance): AppInstance
    {
        return $this->sourceLock->synchronized(
            $appInstance->node_id,
            function () use ($appInstance): AppInstance {
                $snapshot = $appInstance->refresh();

                if ($snapshot->source_kind !== AppInstanceSourceKind::RegisteredWorktree->value) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.source_kind_conflict',
                        message: "AppInstance [{$snapshot->name}] is an Orbit-managed clone; use instance:remove.",
                        status: 409,
                    );
                }

                $snapshot->delete();

                return $snapshot;
            },
        );
    }
}
