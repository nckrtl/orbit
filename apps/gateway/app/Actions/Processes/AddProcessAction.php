<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Data\Processes\ProcessData;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\AgentationUrlProjection;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\AgentationMcpPreset;
use App\Domain\Processes\AnnotatorPreset;
use App\Domain\Processes\AntigravityWatchPreset;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessPresets;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SshProcessUserResolver;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class AddProcessAction
{
    private ProcessRuntimeLease $lease;

    private ProcessSpecification $specifications;

    private AgentationPortAllocator $agentationPorts;

    private AgentationUrlProjection $agentationUrls;

    private AgentationSiteProjection $agentationSites;

    private RecordEventBroadcaster $broadcaster;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessRuntimeLease $lease = null,
        ?ProcessSpecification $specifications = null,
        ?AgentationPortAllocator $agentationPorts = null,
        ?AgentationUrlProjection $agentationUrls = null,
        ?AgentationSiteProjection $agentationSites = null,
        ?RecordEventBroadcaster $broadcaster = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->specifications = $specifications ?? new ProcessSpecification;
        $this->agentationPorts = $agentationPorts ?? app(AgentationPortAllocator::class);
        $this->agentationUrls = $agentationUrls ?? app(AgentationUrlProjection::class);
        $this->agentationSites = $agentationSites ?? app(AgentationSiteProjection::class);
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
    }

    /** @return array{process: Process, created: bool} */
    public function execute(#[SensitiveParameter] AddProcessData $data): array
    {
        if ($data->user !== null && ($data->targetType !== ProcessTargetType::Node || $data->runtime !== ProcessRuntime::Systemd || $data->preset !== null)) {
            throw new ResourceOperationException('process.option_invalid', 'The user option requires a Node systemd Process without a preset.');
        }
        $this->targets->resolve($data->targetType, $data->targetId);
        $ownerIds = $data->targetType === ProcessTargetType::Instance ? [$data->targetId] : [];

        return $this->admissions->run(
            $ownerIds,
            fn (): array => $this->executeOwned($data),
        );
    }

    /** @return array{process: Process, created: bool} */
    private function executeOwned(#[SensitiveParameter] AddProcessData $data): array
    {
        $admission = DB::transaction(fn (): array => $this->reserveAdmission($data));

        return $this->lease->run($admission['process'], function (Process $fresh) use ($admission): array {
            if (! $admission['created'] && ! $this->specifications->matches($fresh, $admission['attributes'])) {
                throw new ResourceOperationException(
                    errorCode: 'process.name_taken',
                    message: "Process [{$fresh->name}] already exists with different configuration.",
                    status: 409,
                );
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

            $fresh = $fresh->refresh();
            $this->projectAgentationSite($fresh);

            if ($admission['created']) {
                $this->broadcaster->broadcast(
                    RecordEventType::ProcessCreated,
                    $fresh->id,
                    ProcessData::fromModel($fresh, $this->runtime->status($fresh))->toArray(),
                );
            }

            return ['process' => $fresh, 'created' => $admission['created']];
        });
    }

    /**
     * @return array{process: Process, created: bool, attributes: array{runtime: ProcessRuntime, working_directory: string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool}}
     */
    private function reserveAdmission(#[SensitiveParameter] AddProcessData $data): array
    {
        $target = match ($data->targetType) {
            ProcessTargetType::Instance => $this->targets->forAdmission(
                Instance::query()
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
        if ($data->user !== null) {
            $target = new ProcessTarget(
                node: $target->node,
                user: $data->user,
                checkoutPath: app(SshProcessUserResolver::class)->home($target->node, $data->user),
            );
        }
        if ($data->preset !== null) {
            $this->assertPresetAdmission($data, $target);
        }
        $attributes = $this->specifications->attributes($data, $target);
        $process = Process::query()
            ->whereIn('owner_type', $data->targetType->storedTypes())
            ->where('owner_id', $data->targetId)
            ->where('name', $data->name)
            ->first() ?? new Process([
                'owner_type' => $data->targetType->storedType(),
                'owner_id' => $data->targetId,
                'name' => $data->name,
            ]);
        if ($process->endpoint_withdrawal_started_at !== null) {
            throw new ResourceOperationException('process.removal_pending', 'Finish removing this Process before creating it again.', 409);
        }
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

        $this->runtime->assertCanStart($process);

        if ($created) {
            $process->fill([
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();
        }

        return ['process' => $process, 'created' => $created, 'attributes' => $attributes];
    }

    private function projectAgentationSite(Process $process): void
    {
        if (! $process->isAgentationMcp() && ! $process->isAnnotator()) {
            return;
        }

        $owner = $process->owner instanceof Instance
            ? $process->owner
            : Instance::query()->find($process->owner_id);

        if ($owner instanceof Instance) {
            $this->agentationSites->project($owner);
            if ($process->isAnnotator()) {
                app(ProcessEnvironmentProjection::class)->project($owner, $process->id);
            }
        }
    }

    private function assertPresetAdmission(AddProcessData $data, ProcessTarget $target): void
    {
        if ($data->preset === null || ! ProcessPresets::isKnown($data->preset)) {
            throw new ResourceOperationException('process.preset_target_invalid', 'The Process preset is not supported.', 422);
        }

        if (! $target->instance?->placedOnAppDev()) {
            throw new ResourceOperationException(
                errorCode: 'process.preset_target_invalid',
                message: "The {$data->preset} preset requires a development Instance.",
                status: 422,
            );
        }

        if ($data->keepAlive && ProcessPresets::refusesKeepAlive($data->preset)) {
            throw new ResourceOperationException(
                errorCode: 'process.preset_keep_alive_invalid',
                message: "The {$data->preset} preset hibernates with the Instance and cannot keep-alive.",
                status: 422,
            );
        }

        $siblings = Process::query()
            ->whereIn('owner_type', Instance::morphTypes())
            ->where('owner_id', $data->targetId)
            ->where('name', '!=', $data->name)
            ->get();

        $duplicate = $siblings->contains(fn (Process $process): bool => ($process->runtime_config['preset'] ?? null) === $data->preset);

        if ($duplicate) {
            throw new ResourceOperationException(
                errorCode: 'process.preset_exists',
                message: "This Instance already has a {$data->preset} Process.",
                status: 409,
            );
        }

        if ($data->preset === AntigravityWatchPreset::NAME && ! $siblings->contains(fn (Process $process): bool => $process->isAgentationMcp())) {
            throw new ResourceOperationException(
                errorCode: 'process.preset_dependency_missing',
                message: 'The antigravity-watch preset requires an agentation-mcp Process on this Instance.',
                status: 422,
            );
        }

        if ($data->preset === AnnotatorPreset::NAME) {
            $this->agentationPorts->assign($target->instance, 'annotator_port');
            $this->agentationUrls->project($target->instance, annotator: true);
        }

        if ($data->preset === AgentationMcpPreset::NAME) {
            $this->agentationPorts->assign($target->instance);
            $this->agentationUrls->project($target->instance);
        }
    }
}
