<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Process;
use SensitiveParameter;

final readonly class StopProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    private RecordEventBroadcaster $broadcaster;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        ?ProcessRuntimeLease $lease = null,
        ?RecordEventBroadcaster $broadcaster = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        return $this->lease->run($process, function (Process $fresh): Process {
            try {
                $this->runtime->stop($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            $fresh->update([
                'desired_state' => DesiredProcessState::Stopped,
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);

            $result = $fresh->refresh();

            $this->broadcaster->broadcast(
                RecordEventType::ProcessStatus,
                $result->id,
                ProcessData::fromModel($result, $this->runtime->status($result))->toArray(),
            );

            return $result;
        });
    }
}
