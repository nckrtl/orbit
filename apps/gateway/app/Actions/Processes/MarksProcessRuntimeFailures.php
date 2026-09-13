<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\ProcessOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Process;
use SensitiveParameter;

trait MarksProcessRuntimeFailures
{
    private function markRuntimeFailed(
        #[SensitiveParameter]
        Process $process,
        #[SensitiveParameter]
        ProcessOperationException $exception,
    ): void {
        if ($exception->errorCode === 'process.runtime_lock_failed') {
            return;
        }

        $process->update([
            'status' => LifecycleStatus::Failed,
            'failed_step' => $exception->step,
            'error_code' => $exception->errorCode,
        ]);
    }
}
