<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Process;
use SensitiveParameter;

final readonly class RemoveProcessAction
{
    use MarksProcessRuntimeFailures;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessTargetResolver $targets,
    ) {}

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        $this->targets->forRemoval($process);
        $process->update(['status' => LifecycleStatus::Removing]);

        try {
            $this->runtime->remove($process);
        } catch (ProcessOperationException $exception) {
            $this->markRuntimeFailed($process, $exception);

            throw $exception;
        }

        $process->delete();

        return $process;
    }
}
