<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Process;
use SensitiveParameter;

final readonly class StartProcessAction
{
    use MarksProcessRuntimeFailures;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
    ) {}

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        if ($process->owner_type !== AppInstance::class) {
            return $this->executeCurrent($process);
        }

        $processId = $process->id;
        $ownerId = $process->owner_id;

        return $this->admissions->run(
            [$ownerId],
            fn (): Process => $this->executeCurrent(
                Process::query()
                    ->whereKey($processId)
                    ->where('owner_type', AppInstance::class)
                    ->where('owner_id', $ownerId)
                    ->firstOrFail(),
            ),
        );
    }

    private function executeCurrent(#[SensitiveParameter] Process $process): Process
    {
        try {
            $this->runtime->start($process);
        } catch (ProcessOperationException $exception) {
            $this->markRuntimeFailed($process, $exception);

            throw $exception;
        }
        $process->update([
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $process->refresh();
    }
}
