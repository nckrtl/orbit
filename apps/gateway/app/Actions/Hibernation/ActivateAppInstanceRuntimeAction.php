<?php

declare(strict_types=1);

namespace App\Actions\Hibernation;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
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
        private AppInstanceCheckoutInspector $checkouts,
    ) {}

    public function execute(#[SensitiveParameter] AppInstance $instance): void
    {
        $instanceId = (int) $instance->getKey();
        $nodeId = $instance->node_id;
        $checkoutPath = $instance->checkout_path;

        try {
            $this->admissions->run([$instanceId], function () use ($instanceId, $nodeId, $checkoutPath): void {
                $instance = $this->currentInstance($instanceId, $nodeId, $checkoutPath);
                $key = RuntimeHibernation::key($instanceId);

                if ($this->markers->isCold($instance->node, $key)) {
                    $this->checkouts->restore($instance, $this->checkouts->inspect($instance));
                }

                $running = $this->desiredRunning($instance);

                foreach ($running as $process) {
                    $this->runtime->start($process);
                }

                $this->readiness->waitUntilReady($instance, $running);

                if ($this->markers->isCold($instance->node, $key)) {
                    $this->markers->clearCold($instance->node, $key);
                }

                $this->markers->markAwake($instance->node, $key);
            });
        } catch (ProcessOperationException|ResourceOperationException $exception) {
            throw new HibernationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            );
        }
    }

    private function currentInstance(int $instanceId, int $nodeId, string $checkoutPath): AppInstance
    {
        $instance = AppInstance::query()
            ->whereKey($instanceId)
            ->where('node_id', $nodeId)
            ->where('checkout_path', $checkoutPath)
            ->with(['node.roles', 'processes'])
            ->first();

        if (
            ! $instance instanceof AppInstance
            || StoragePath::tryParse($checkoutPath) === null
            || $instance->environment !== 'development'
            || $instance->status !== AppInstanceState::Active
            || $instance->migration_required
            || $instance->provisioning_step !== 'active'
            || $instance->node->status !== LifecycleStatus::Active
            || ! $this->policy->appliesToInstance($instance)
        ) {
            throw new HibernationException(
                errorCode: 'hibernation.target_ineligible',
                message: "AppInstance [{$instanceId}] is not an active app-dev development target at the requested placement.",
                status: 404,
            );
        }

        return $instance;
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
