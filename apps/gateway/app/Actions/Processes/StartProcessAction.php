<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Process;
use SensitiveParameter;

final readonly class StartProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessRuntimeLease $lease = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        if ($process->owner_type !== AppInstance::class) {
            return $this->mutate($process);
        }

        $processId = $process->id;
        $ownerId = $process->owner_id;

        return $this->admissions->run(
            [$ownerId],
            fn (): Process => $this->mutate(
                Process::query()
                    ->whereKey($processId)
                    ->where('owner_type', AppInstance::class)
                    ->where('owner_id', $ownerId)
                    ->firstOrFail(),
            ),
        );
    }

    private function mutate(#[SensitiveParameter] Process $process): Process
    {
        return $this->lease->run($process, function (Process $fresh): Process {
            try {
                $this->runtime->start($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            $fresh->update([
                'desired_state' => DesiredProcessState::Running,
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return $fresh->refresh();
        });
    }
}
