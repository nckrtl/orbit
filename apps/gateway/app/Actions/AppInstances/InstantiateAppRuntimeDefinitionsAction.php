<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\Processes\AddProcessData;
use App\Domain\AppDefinitions\DefinitionEnvironment;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class InstantiateAppRuntimeDefinitionsAction
{
    public function __construct(
        private ProcessAdmissionLock $admissions,
        private ProcessTargetResolver $processTargets,
        private ProcessSpecification $processSpecifications,
        private ProcessRuntimeManager $processRuntime,
        private ScheduleRuntimeManager $scheduleRuntime,
    ) {}

    public function execute(AppInstance $appInstance): AppInstance
    {
        $appInstanceId = $appInstance->id;

        return $this->admissions->run(
            [$appInstanceId],
            fn (): AppInstance => $this->executeOwned($appInstanceId),
        );
    }

    private function executeOwned(int $appInstanceId): AppInstance
    {
        $appInstance = $this->capture($appInstanceId);

        Process::query()
            ->where('owner_type', AppInstance::class)
            ->where('owner_id', $appInstanceId)
            ->whereNotNull('source_definition_id')
            ->orderBy('id')
            ->get()
            ->each(fn (Process $process) => $this->installProcess($process));

        Schedule::query()
            ->where('target_type', AppInstance::class)
            ->where('target_id', $appInstanceId)
            ->whereNotNull('source_definition_id')
            ->orderBy('id')
            ->get()
            ->each(fn (Schedule $schedule) => $this->installSchedule($schedule));

        return $appInstance->refresh();
    }

    private function capture(int $appInstanceId): AppInstance
    {
        return DB::transaction(function () use ($appInstanceId): AppInstance {
            $appInstance = AppInstance::query()
                ->with(['app', 'node'])
                ->lockForUpdate()
                ->findOrFail($appInstanceId);
            $this->assertProductionTarget($appInstance);
            $processTarget = $this->processTargets->forPreparation($appInstance);

            if ($appInstance->runtime_definitions_captured_at !== null) {
                return $appInstance;
            }

            $processDefinitions = ProcessDefinition::query()
                ->where('app_id', $appInstance->app_id)
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter($this->isForProduction(...));
            $scheduleDefinitions = ScheduleDefinition::query()
                ->where('app_id', $appInstance->app_id)
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter($this->isForProduction(...));

            foreach ($processDefinitions as $definition) {
                $this->captureProcess($appInstance, $definition, $processTarget);
            }

            foreach ($scheduleDefinitions as $definition) {
                $this->captureSchedule($appInstance, $definition);
            }

            $appInstance->update(['runtime_definitions_captured_at' => now()]);

            return $appInstance->refresh();
        });
    }

    private function assertProductionTarget(AppInstance $appInstance): void
    {
        if ($appInstance->environment === DefinitionEnvironment::Production->value) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'instance.runtime_definitions_target_invalid',
            message: 'Runtime definitions can be instantiated only for a production AppInstance.',
            status: 409,
        );
    }

    private function isForProduction(ProcessDefinition|ScheduleDefinition $definition): bool
    {
        return in_array(DefinitionEnvironment::Production->value, $definition->environments, strict: true);
    }

    private function captureProcess(
        AppInstance $appInstance,
        #[SensitiveParameter]
        ProcessDefinition $definition,
        ProcessTarget $target,
    ): void {
        $data = $this->processData($appInstance, $definition);
        $attributes = $this->processSpecifications->attributes($data, $target);

        try {
            Process::query()->create([
                'owner_type' => AppInstance::class,
                'owner_id' => $appInstance->id,
                'source_definition_id' => $definition->id,
                'name' => $definition->name,
                ...$attributes,
                'desired_state' => DesiredProcessState::Stopped,
                'status' => LifecycleStatus::Provisioning,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ResourceOperationException(
                errorCode: 'process.name_taken',
                message: "Process [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
                previous: $exception,
            );
        }
    }

    private function captureSchedule(
        AppInstance $appInstance,
        #[SensitiveParameter]
        ScheduleDefinition $definition,
    ): void {
        $specification = $definition->spec;

        try {
            Schedule::query()->create([
                'target_type' => AppInstance::class,
                'target_id' => $appInstance->id,
                'source_definition_id' => $definition->id,
                'host_node_id' => $appInstance->node_id,
                'name' => $definition->name,
                'calendar' => $specification['calendar'],
                'command' => $specification['command'],
                'timeout_seconds' => $specification['timeout_seconds'],
                'desired_timer_state' => DesiredTimerState::Disabled,
                'status' => LifecycleStatus::Provisioning,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ResourceOperationException(
                errorCode: ScheduleErrorCode::RetryConflict->value,
                message: "Schedule [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
                previous: $exception,
            );
        }
    }

    private function processData(
        AppInstance $appInstance,
        #[SensitiveParameter]
        ProcessDefinition $definition,
    ): AddProcessData {
        $specification = $definition->spec;
        /** @var list<string> $command */
        $command = $specification['command'];
        /** @var array<string, string> $environment */
        $environment = $specification['environment'] ?? [];
        /** @var list<string> $ports */
        $ports = $specification['ports'] ?? [];
        /** @var list<array{source: string, target: string, read_only: bool}> $volumes */
        $volumes = $specification['volumes'] ?? [];

        return new AddProcessData(
            targetType: ProcessTargetType::AppInstance,
            targetId: $appInstance->id,
            name: $definition->name,
            runtime: ProcessRuntime::from($specification['runtime']),
            command: $command,
            image: $specification['image'] ?? null,
            workingDirectory: $specification['working_directory'] ?? null,
            environment: $environment,
            ports: $ports,
            volumes: $volumes,
            restartPolicy: $specification['restart_policy'] ?? 'unless-stopped',
            start: false,
        );
    }

    private function installProcess(#[SensitiveParameter] Process $process): void
    {
        if (in_array($process->status, [LifecycleStatus::Active, LifecycleStatus::Removing], strict: true)) {
            return;
        }

        try {
            $this->processRuntime->converge($process);
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
    }

    private function installSchedule(#[SensitiveParameter] Schedule $schedule): void
    {
        if (in_array($schedule->status, [LifecycleStatus::Active, LifecycleStatus::Removing], strict: true)) {
            return;
        }

        try {
            $this->scheduleRuntime->install($schedule);
        } catch (ScheduleOperationException $exception) {
            $schedule->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => $exception->step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }

        $schedule->update([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);
    }
}
