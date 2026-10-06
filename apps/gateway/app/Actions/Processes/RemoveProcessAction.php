<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Instances\AdmitInstanceAppMutationAction;
use App\Domain\Analytics\AnalyticsProcessOwnership;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\AgentationUrlProjection;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseServers\DatabaseServerProcessOwnership;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\ProxyCli\ProxyCliProcessOwnership;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\Process;
use App\Models\RouteCustomProxy;
use SensitiveParameter;

final readonly class RemoveProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    private AgentationPortAllocator $agentationPorts;

    private AgentationUrlProjection $agentationUrls;

    private AgentationSiteProjection $agentationSites;

    private RecordEventBroadcaster $broadcaster;

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessTargetResolver $targets,
        ?ProcessRuntimeLease $lease = null,
        ?AgentationPortAllocator $agentationPorts = null,
        ?AgentationUrlProjection $agentationUrls = null,
        ?AgentationSiteProjection $agentationSites = null,
        ?RecordEventBroadcaster $broadcaster = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->agentationPorts = $agentationPorts ?? app(AgentationPortAllocator::class);
        $this->agentationUrls = $agentationUrls ?? app(AgentationUrlProjection::class);
        $this->agentationSites = $agentationSites ?? app(AgentationSiteProjection::class);
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
    }

    /** `$removedByOwningRole` is true only when the analytics role removes its own `plausible` Process, or a Database server removes its own Process. */
    public function execute(#[SensitiveParameter] Process $process, bool $removedByOwningRole = false): Process
    {
        $ids = Instance::isMorphType($process->owner_type) ? [$process->owner_id] : [];

        return app(AdmitInstanceAppMutationAction::class)->execute($ids, fn (): Process => $this->executeOwned($process, $removedByOwningRole));
    }

    private function executeOwned(#[SensitiveParameter] Process $process, bool $removedByOwningRole): Process
    {
        return $this->lease->run($process, function (Process $fresh) use ($removedByOwningRole): Process {
            if ($fresh->owner_type === Instance::MorphAlias && $fresh->owner instanceof Instance) {
                AppRuntimeMigration::assertInstanceAvailable($fresh->owner);
            }
            if (! $removedByOwningRole) {
                app(AnalyticsProcessOwnership::class)->assertRemovable($fresh);
                app(ProxyCliProcessOwnership::class)->assertRemovable($fresh);
                app(DatabaseServerProcessOwnership::class)->assertRemovable($fresh);
            }

            if (RouteCustomProxy::query()->where('process_id', $fresh->id)->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'process.has_routes',
                    message: "Process [{$fresh->name}] is still targeted by a custom proxy Route.",
                    status: 409,
                );
            }

            if ($fresh->isAgentationMcp() && Process::query()->where('owner_type', $fresh->owner_type)->where('owner_id', $fresh->owner_id)->where('app', $fresh->app)->where('id', '!=', $fresh->id)->get()->contains(fn (Process $process): bool => $process->isAntigravityWatch())) {
                throw new ResourceOperationException(
                    errorCode: 'process.has_dependent',
                    message: "Process [{$fresh->name}] is still required by an antigravity-watch Process.",
                    status: 409,
                );
            }

            $this->targets->forRemoval($fresh);
            $fresh->update(['status' => LifecycleStatus::Removing, 'desired_state' => DesiredProcessState::Stopped]);

            try {
                $this->runtime->remove($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            if (($fresh->isAgentationMcp() || $fresh->isAnnotator()) && $fresh->owner instanceof Instance) {
                // The persisted intent removes the proxy from every subsequent Caddy render,
                // but the port remains reserved until projection confirms withdrawal.
                if ($fresh->endpoint_withdrawal_started_at === null) {
                    $fresh->update(['endpoint_withdrawal_started_at' => now()]);
                }
                if ($fresh->endpoint_withdrawn_at === null) {
                    $this->agentationSites->project($fresh->owner);
                    $fresh->update(['endpoint_withdrawn_at' => now()]);
                }
                $annotator = $fresh->isAnnotator();
                $this->agentationPorts->release($fresh->owner, $annotator ? 'annotator_port' : 'agentation_port', $fresh->app);
                $this->agentationUrls->forget($fresh->owner, annotator: $annotator, app: $fresh->app);
                if ($annotator && $fresh->owner->status === InstanceState::Active) {
                    app(ProcessEnvironmentProjection::class)->project($fresh->owner, $fresh->id);
                }
            }

            $fresh->delete();

            $this->broadcaster->broadcast(
                RecordEventType::ProcessDeleted,
                $fresh->id,
                ['id' => $fresh->id, 'name' => $fresh->name],
            );

            return $fresh;
        });
    }
}
