<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Models\AppInstance;
use SensitiveParameter;

use function Illuminate\Support\defer;

final readonly class ScheduleAppInstanceRuntimeWakeAction
{
    public function __construct(
        private ActivateAppInstanceRuntimeAction $activate,
        private HibernationWakeFailureStore $failures,
    ) {}

    public function afterResponse(#[SensitiveParameter] AppInstance $instance): void
    {
        $instanceId = (int) $instance->getKey();

        defer(callback: function () use ($instance, $instanceId): void {
            try {
                $this->activate->execute($instance);
            } catch (HibernationException $exception) {
                if (in_array($exception->errorCode, ['process.operation_busy', 'process.runtime_lock_failed'], true)) {
                    return;
                }

                $this->failures->remember($instanceId, $exception->getMessage());
            }
        }, always: true);
    }
}
