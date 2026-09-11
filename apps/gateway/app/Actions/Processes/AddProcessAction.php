<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class AddProcessAction
{
    private ProcessSpecification $specifications;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessSpecification $specifications = null,
    ) {
        $this->specifications = $specifications ?? new ProcessSpecification;
    }

    /** @return array{process: Process, created: bool} */
    public function execute(#[SensitiveParameter] AddProcessData $data): array
    {
        $this->targets->resolve($data->targetType, $data->targetId);

        return $this->admissions->run(
            [$data->targetId],
            fn (): array => $this->executeOwned($data),
        );
    }

    /** @return array{process: Process, created: bool} */
    private function executeOwned(#[SensitiveParameter] AddProcessData $data): array
    {
        /** @var array{process: Process, created: bool} $admission */
        $admission = DB::transaction(function () use ($data): array {
            $instance = AppInstance::query()
                ->with('node')
                ->lockForUpdate()
                ->findOrFail($data->targetId);
            $target = $this->targets->forAdmission($instance);
            $attributes = $this->specifications->attributes($data, $target);
            $process = Process::query()->firstOrNew([
                'owner_type' => $data->targetType->modelClass(),
                'owner_id' => $data->targetId,
                'name' => $data->name,
            ]);
            $created = ! $process->exists;
            $desiredState = $process->desired_state;

            if ($created) {
                $desiredState = $data->start ? DesiredProcessState::Running : DesiredProcessState::Stopped;
            }

            if ($process->exists && ! $this->specifications->matches($process, $attributes)) {
                throw new ResourceOperationException(
                    errorCode: 'process.name_taken',
                    message: "Process [{$data->name}] already exists with different configuration.",
                    status: 409,
                );
            }

            $process->fill([
                ...$attributes,
                'desired_state' => $desiredState,
            ]);

            if ($desiredState === DesiredProcessState::Running) {
                $this->runtime->assertCanStart($process);
            }

            $process->fill([
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            return ['process' => $process, 'created' => $created];
        });

        $process = $admission['process'];

        try {
            $this->runtime->converge($process);
        } catch (ProcessOperationException $exception) {
            $process->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => $exception->step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }

        $process->update([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return ['process' => $process->refresh(), 'created' => $admission['created']];
    }
}
