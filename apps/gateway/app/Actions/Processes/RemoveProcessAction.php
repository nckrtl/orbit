<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\AgentationUrlProjection;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
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

    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessTargetResolver $targets,
        ?ProcessRuntimeLease $lease = null,
        ?AgentationPortAllocator $agentationPorts = null,
        ?AgentationUrlProjection $agentationUrls = null,
        ?AgentationSiteProjection $agentationSites = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->agentationPorts = $agentationPorts ?? app(AgentationPortAllocator::class);
        $this->agentationUrls = $agentationUrls ?? app(AgentationUrlProjection::class);
        $this->agentationSites = $agentationSites ?? app(AgentationSiteProjection::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        return $this->lease->run($process, function (Process $fresh): Process {
            if (RouteCustomProxy::query()->where('process_id', $fresh->id)->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'process.has_routes',
                    message: "Process [{$fresh->name}] is still targeted by a custom proxy Route.",
                    status: 409,
                );
            }

            if ($fresh->isAgentationMcp() && Process::query()->where('owner_type', $fresh->owner_type)->where('owner_id', $fresh->owner_id)->where('id', '!=', $fresh->id)->get()->contains(fn (Process $process): bool => $process->isAntigravityWatch())) {
                throw new ResourceOperationException(
                    errorCode: 'process.has_dependent',
                    message: "Process [{$fresh->name}] is still required by an antigravity-watch Process.",
                    status: 409,
                );
            }

            $this->targets->forRemoval($fresh);
            $fresh->update(['status' => LifecycleStatus::Removing]);

            try {
                $this->runtime->remove($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            if ($fresh->isAgentationMcp() && $fresh->owner instanceof AppInstance) {
                $this->agentationPorts->release($fresh->owner);
                $this->agentationUrls->forget($fresh->owner);
                $this->agentationSites->project($fresh->owner);
            }

            $fresh->delete();

            return $fresh;
        });
    }
}
