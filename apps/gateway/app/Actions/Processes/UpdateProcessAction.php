<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseServers\DatabaseServerProcessOwnership;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SshProcessUserResolver;
use App\Models\Instance;
use App\Models\Process;
use SensitiveParameter;

final readonly class UpdateProcessAction
{
    use MarksProcessRuntimeFailures;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeLease $lease,
        private ProcessTargetResolver $targets,
        private ProcessSpecification $specifications,
        private SshProcessUserResolver $users,
        private RecordEventBroadcaster $broadcaster,
        private DatabaseServerProcessOwnership $databaseServers,
    ) {}

    public function execute(#[SensitiveParameter] Process $process, #[SensitiveParameter] AddProcessData $data): Process
    {
        return $this->admissions->run(
            Instance::isMorphType($process->owner_type) ? [$process->owner_id] : [],
            fn (): Process => $this->lease->run($process, function (Process $fresh) use ($data): Process {
                $this->databaseServers->assertUpdatable($fresh);
                if (isset($fresh->runtime_config['preset'])) {
                    throw new ResourceOperationException('process.option_invalid', 'Preset Processes cannot be updated.', 422);
                }
                $target = $this->targets->forInstallation($fresh);
                if ($data->user !== null) {
                    $home = $this->users->home($target->node, $data->user);
                    $data = new AddProcessData(
                        $data->targetType, $data->targetId, $data->name, $data->runtime,
                        $data->command, $data->image, $data->workingDirectory ?? $home,
                        $data->environment, $data->ports, $data->volumes, $data->restartPolicy,
                        false, $data->keepAlive, user: $data->user, restartOnDeploy: $data->restartOnDeploy,
                    );
                }
                $attributes = $this->specifications->attributes($data, $target);
                $this->runtime->status($fresh);
                $retiredRuntime = $fresh->runtime !== $data->runtime ? $fresh->runtime : $fresh->retired_runtime;
                if ($retiredRuntime !== null) {
                    $retired = clone $fresh;
                    $retired->runtime = $retiredRuntime;
                    $this->runtime->status($retired);
                }
                $fresh->fill($attributes);
                $this->runtime->status($fresh);
                if ($fresh->desired_state === DesiredProcessState::Running) {
                    $this->runtime->assertCanStart($fresh);
                }
                $fresh->retired_runtime = $retiredRuntime;
                $fresh->fill(['status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null])->save();
                try {
                    if ($fresh->retired_runtime !== null) {
                        // Keep cleanup intent until removal completes; a crash can safely repeat it.
                        $retired = clone $fresh;
                        $retired->runtime = $fresh->retired_runtime;
                        $this->runtime->remove($retired);
                        $fresh->retired_runtime = null;
                        $fresh->save();
                    }
                    $this->runtime->converge($fresh);
                } catch (ProcessOperationException $exception) {
                    $this->markRuntimeFailed($fresh, $exception);
                    throw $exception;
                }
                $fresh->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
                $result = $fresh->refresh();
                $this->broadcaster->broadcast(RecordEventType::ProcessStatus, $result->id,
                    ProcessData::fromModel($result, $this->runtime->status($result))->toArray());

                return $result;
            }),
        );
    }
}
