<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Process;
use SensitiveParameter;

final readonly class RemoveProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessTargetResolver $targets,
        ?ProcessRuntimeLease $lease = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        return $this->lease->run($process, function (Process $fresh): Process {
            $this->targets->forRemoval($fresh);
            $fresh->update(['status' => LifecycleStatus::Removing]);

            try {
                $this->runtime->remove($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            $fresh->delete();

            return $fresh;
        });
    }
}
