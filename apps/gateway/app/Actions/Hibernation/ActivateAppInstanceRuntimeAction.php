<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Process;
use SensitiveParameter;

final readonly class ActivateAppInstanceRuntimeAction
{
    public function __construct(
        private AppDevHibernationPolicy $policy,
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeManager $runtime,
        private HibernationMarkerStore $markers,
        private AppInstanceRuntimeReadiness $readiness,
    ) {}

    public function execute(#[SensitiveParameter] AppInstance $instance): void
    {
        $instance->loadMissing('node');

        if (! $this->policy->appliesToInstance($instance)) {
            throw new HibernationException(
                errorCode: 'hibernation.target_ineligible',
                message: "AppInstance [{$instance->name}] is not an app-dev development target.",
                status: 404,
            );
        }

        $instanceId = (int) $instance->getKey();

        try {
            $this->admissions->run([$instanceId], function () use ($instance, $instanceId): void {
                $instance->load('processes');

                $running = $this->desiredRunning($instance);

                foreach ($running as $process) {
                    $this->runtime->start($process);
                }

                $this->readiness->waitUntilReady($instance, $running);
                $this->markers->markAwake($instance->node, RuntimeHibernation::key($instanceId));
            });
        } catch (ProcessOperationException $exception) {
            throw new HibernationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            );
        } catch (ResourceOperationException $exception) {
            throw new HibernationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            );
        }
    }

    /** @return list<Process> */
    private function desiredRunning(AppInstance $instance): array
    {
        return $instance->processes
            ->filter(static fn (Process $process): bool => $process->desired_state === DesiredProcessState::Running)
            ->sortBy('id')
            ->values()
            ->all();
    }
}
