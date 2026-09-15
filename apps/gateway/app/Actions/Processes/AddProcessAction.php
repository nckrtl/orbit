<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class AddProcessAction
{
    private ProcessRuntimeLease $lease;

    private ProcessSpecification $specifications;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessRuntimeLease $lease = null,
        ?ProcessSpecification $specifications = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->specifications = $specifications ?? new ProcessSpecification;
    }

    /** @return array{process: Process, created: bool} */
    public function execute(#[SensitiveParameter] AddProcessData $data): array
    {
        $this->targets->resolve($data->targetType, $data->targetId);
        $ownerIds = $data->targetType === ProcessTargetType::AppInstance ? [$data->targetId] : [];

        return $this->admissions->run(
            $ownerIds,
            fn (): array => $this->executeOwned($data),
        );
    }

    /** @return array{process: Process, created: bool} */
    private function executeOwned(#[SensitiveParameter] AddProcessData $data): array
    {
        /** @var array{process: Process, created: bool, attributes: array<string, mixed>} $admission */
        $admission = DB::transaction(function () use ($data): array {
            $target = match ($data->targetType) {
                ProcessTargetType::AppInstance => $this->targets->forAdmission(
                    AppInstance::query()
                        ->with('node')
                        ->lockForUpdate()
                        ->findOrFail($data->targetId),
                ),
                ProcessTargetType::Node => $this->targets->forNodeAdmission(
                    Node::query()
                        ->lockForUpdate()
                        ->findOrFail($data->targetId),
                ),
            };
            if ($data->preset !== null) {
                if ($data->preset !== VpDevPreset::NAME || $target->appInstance?->environment !== 'development') {
                    throw new ResourceOperationException('process.preset_target_invalid', 'The vp-dev preset requires a development AppInstance.', 422);
                }
                $other = Process::query()->where('owner_type', AppInstance::class)->where('owner_id', $data->targetId)->where('name', '!=', $data->name)->get()->contains(fn (Process $process): bool => $process->isVpDev());
                if ($other) {
                    throw new ResourceOperationException('process.preset_exists', 'This AppInstance already has a vp-dev Process.', 409);
                }
            }
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

            if ($created) {
                $process->fill([
                    'status' => LifecycleStatus::Provisioning,
                    'failed_step' => null,
                    'error_code' => null,
                ])->save();
            }

            return ['process' => $process, 'created' => $created, 'attributes' => $attributes];
        });

        return $this->lease->run($admission['process'], function (Process $fresh) use ($admission): array {
            if (! $admission['created'] && ! $this->specifications->matches($fresh, $admission['attributes'])) {
                throw new ResourceOperationException(
                    errorCode: 'process.name_taken',
                    message: "Process [{$fresh->name}] already exists with different configuration.",
                    status: 409,
                );
            }

            if ($fresh->desired_state === DesiredProcessState::Running) {
                $this->runtime->assertCanStart($fresh);
            }

            $fresh->fill([
                ...$admission['attributes'],
                'desired_state' => $fresh->desired_state,
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            try {
                $this->runtime->converge($fresh);
            } catch (ProcessOperationException $exception) {
                $fresh->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => $exception->step,
                    'error_code' => $exception->errorCode,
                ]);

                throw $exception;
            }

            $fresh->update([
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return ['process' => $fresh->refresh(), 'created' => $admission['created']];
        });
    }
}
