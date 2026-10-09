<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\ProjectDefinitions\DefinitionEnvironment;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class InstantiateProjectRuntimeDefinitionsAction
{
    public function __construct(
        private ProcessAdmissionLock $admissions,
        private ProcessTargetResolver $processTargets,
        private ProcessSpecification $processSpecifications,
        private ProcessRuntimeManager $processRuntime,
        private ScheduleRuntimeManager $scheduleRuntime,
    ) {}

    public function captureForClone(Instance $instance): Instance
    {
        $instanceId = $instance->id;

        return $this->admissions->run(
            [$instanceId],
            fn (): Instance => $this->capture($instanceId),
        );
    }

    public function installCaptured(Instance $instance): Instance
    {
        $instanceId = $instance->id;

        return $this->admissions->run(
            [$instanceId],
            fn (): Instance => $this->installCapturedOwned($instanceId),
        );
    }

    public function execute(Instance $instance): Instance
    {
        $instanceId = $instance->id;

        return $this->admissions->run(
            [$instanceId],
            function () use ($instanceId): Instance {
                $this->capture($instanceId);

                return $this->installCapturedOwned($instanceId);
            },
        );
    }

    private function installCapturedOwned(int $instanceId): Instance
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($instanceId);

        if ($instance->runtime_definitions_captured_at === null) {
            return $instance;
        }

        Process::query()
            ->whereIn('owner_type', Instance::morphTypes())
            ->where('owner_id', $instanceId)
            ->whereNotNull('source_definition_id')
            ->orderBy('id')
            ->get()
            ->each(fn (Process $process) => $this->installProcess($process));

        Schedule::query()
            ->whereIn('target_type', Instance::morphTypes())
            ->where('target_id', $instanceId)
            ->whereNotNull('source_definition_id')
            ->orderBy('id')
            ->get()
            ->each(fn (Schedule $schedule) => $this->installSchedule($schedule));

        return $instance->refresh();
    }

    private function capture(int $instanceId): Instance
    {
        return DB::transaction(function () use ($instanceId): Instance {
            $instance = Instance::query()
                ->with(['project', 'node'])
                ->lockForUpdate()
                ->findOrFail($instanceId);
            $this->assertProductionTarget($instance);

            if ($instance->runtime_definitions_captured_at !== null) {
                return $instance;
            }

            $processDefinitions = ProcessDefinition::query()
                ->where('project_id', $instance->project_id)
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter($this->isForProduction(...));
            $scheduleDefinitions = ScheduleDefinition::query()
                ->where('project_id', $instance->project_id)
                ->orderBy('name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter($this->isForProduction(...));

            foreach ($processDefinitions as $definition) {
                $this->captureProcess($instance, $definition, $this->processTargets->forPreparation($instance, $definition->app));
            }

            foreach ($scheduleDefinitions as $definition) {
                $this->captureSchedule($instance, $definition);
            }

            $instance->update(['runtime_definitions_captured_at' => now()]);

            return $instance->refresh();
        });
    }

    private function assertProductionTarget(Instance $instance): void
    {
        if ($instance->placedOnAppProd()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'instance.runtime_definitions_target_invalid',
            message: 'Runtime definitions can be instantiated only for a production Instance.',
            status: 409,
        );
    }

    private function isForProduction(ProcessDefinition|ScheduleDefinition $definition): bool
    {
        return in_array(DefinitionEnvironment::Production->value, $definition->environments, strict: true);
    }

    private function captureProcess(
        Instance $instance,
        #[SensitiveParameter]
        ProcessDefinition $definition,
        ProcessTarget $target,
    ): void {
        $data = $this->processData($instance, $definition);
        $attributes = $this->processSpecifications->attributes($data, $target);

        if (Process::query()
            ->whereIn('owner_type', Instance::morphTypes())
            ->where('owner_id', $instance->id)
            ->where('name', $definition->name)
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: 'process.name_taken',
                message: "Process [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
            );
        }

        try {
            Process::query()->create([
                'owner_type' => Instance::MorphAlias,
                'owner_id' => $instance->id,
                'source_definition_id' => $definition->id,
                'app' => $definition->app,
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
        Instance $instance,
        #[SensitiveParameter]
        ScheduleDefinition $definition,
    ): void {
        $specification = $definition->spec;

        if (Schedule::query()
            ->whereIn('target_type', Instance::morphTypes())
            ->where('target_id', $instance->id)
            ->where('name', $definition->name)
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: ScheduleErrorCode::RetryConflict->value,
                message: "Schedule [{$definition->name}] already exists and cannot be adopted as a definition copy.",
                status: 409,
            );
        }

        try {
            Schedule::query()->create([
                'target_type' => Instance::MorphAlias,
                'target_id' => $instance->id,
                'source_definition_id' => $definition->id,
                'app' => $definition->app,
                'host_node_id' => $instance->node_id,
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
        Instance $instance,
        #[SensitiveParameter]
        ProcessDefinition $definition,
    ): AddProcessData {
        $specification = $definition->spec;
        $runtime = $specification['runtime'] ?? null;
        $command = $this->stringList($specification['command'] ?? null);
        $image = $specification['image'] ?? null;
        $workingDirectory = $specification['working_directory'] ?? null;
        $restartPolicy = $specification['restart_policy'] ?? 'never';

        if (
            ! is_string($runtime)
            || ($image !== null && ! is_string($image))
            || ($workingDirectory !== null && ! is_string($workingDirectory))
            || ! is_string($restartPolicy)
        ) {
            throw new \RuntimeException('Process definition contains invalid runtime data.');
        }

        $environment = $this->stringMap($specification['environment'] ?? []);
        $ports = $this->stringList($specification['ports'] ?? []);
        $volumes = $this->volumes($specification['volumes'] ?? []);

        return new AddProcessData(
            targetType: ProcessTargetType::Instance,
            targetId: $instance->id,
            name: $definition->name,
            runtime: ProcessRuntime::from($runtime),
            command: $command,
            image: $image,
            workingDirectory: $workingDirectory,
            environment: $environment,
            ports: $ports,
            volumes: $volumes,
            restartPolicy: $restartPolicy,
            start: false,
            keepAlive: ($specification['keep_alive'] ?? false) === true,
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \RuntimeException('Process definition contains invalid list data.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \RuntimeException('Process definition contains invalid list data.');
            }
        }

        return $value;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \RuntimeException('Process definition contains invalid map data.');
        }

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw new \RuntimeException('Process definition contains invalid map data.');
            }
        }

        return $value;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    private function volumes(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \RuntimeException('Process definition contains invalid volume data.');
        }

        $volumes = [];
        foreach ($value as $volume) {
            $readOnly = is_array($volume) ? ($volume['read_only'] ?? false) : null;
            if (
                ! is_array($volume)
                || ! is_string($volume['source'] ?? null)
                || ! is_string($volume['target'] ?? null)
                || ! in_array($readOnly, [true, false, 1, 0, '1', '0'], strict: true)
            ) {
                throw new \RuntimeException('Process definition contains invalid volume data.');
            }

            $volumes[] = [
                'source' => $volume['source'],
                'target' => $volume['target'],
                'read_only' => in_array($readOnly, [true, 1, '1'], strict: true),
            ];
        }

        return $volumes;
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
