<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Process;
use SensitiveParameter;

final readonly class StartProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    private RecordEventBroadcaster $broadcaster;

    private ProcessRuntimeStatusIndex $statuses;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessRuntimeLease $lease = null,
        ?RecordEventBroadcaster $broadcaster = null,
        ?ProcessRuntimeStatusIndex $statuses = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
        $this->statuses = $statuses ?? app(ProcessRuntimeStatusIndex::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        if (! AppInstance::isMorphType($process->owner_type)) {
            return $this->mutate($process);
        }

        $processId = $process->id;
        $ownerId = $process->owner_id;

        return $this->admissions->run(
            [$ownerId],
            fn (): Process => $this->mutate(
                Process::query()
                    ->whereKey($processId)
                    ->whereIn('owner_type', AppInstance::morphTypes())
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

            $result = $fresh->refresh();
            $status = $this->runtime->status($result);
            $this->statuses->remember($result, $status);

            $this->broadcaster->broadcast(
                RecordEventType::ProcessStatus,
                $result->id,
                ProcessData::fromModel($result, $status)->toArray(),
            );

            return $result;
        });
    }
}
