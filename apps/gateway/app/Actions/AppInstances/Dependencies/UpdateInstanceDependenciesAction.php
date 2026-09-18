<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyUpdateInspection;
use App\Domain\AppInstances\Dependencies\DependencyUpdateStepResult;
use App\Domain\AppInstances\Dependencies\InstanceDependencyUpdateResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use Closure;

final readonly class UpdateInstanceDependenciesAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppDevSourceOperationLock $sourceOperations,
        private UpdateComposerDependenciesAction $composer,
        private UpdateYarnDependenciesAction $yarn,
        private UpdateNpmDependenciesAction $npm,
        private UpdatePnpmDependenciesAction $pnpm,
        private UpdateBunDependenciesAction $bun,
        private ScanInstanceDependenciesAction $scan,
    ) {}

    /** @param  (Closure(): bool)|null  $cancelled */
    public function execute(AppInstance $instance, ?Closure $cancelled = null): InstanceDependencyUpdateResult
    {
        try {
            return $this->operations->run([$instance->id], function () use ($instance, $cancelled): InstanceDependencyUpdateResult {
                $current = AppInstance::query()->with('node')->find($instance->id);
                if ($current === null || ! $this->available($current)) {
                    return $this->refused($instance->id, 'dependencies.instance_unavailable');
                }
                if ($current->environment === 'production') {
                    return $this->refused($current->id, 'dependencies.production_update_forbidden');
                }

                return $this->sourceOperations->synchronized(
                    $current->node_id,
                    fn (): InstanceDependencyUpdateResult => $this->update($current, $cancelled),
                );
            });
        } catch (ResourceOperationException) {
            return $this->refused($instance->id, 'dependencies.operation_busy');
        }
    }

    /** @param  (Closure(): bool)|null  $cancelled */
    private function update(AppInstance $instance, ?Closure $cancelled): InstanceDependencyUpdateResult
    {
        $yarn = $this->yarn->inspect($instance, $cancelled);
        if ($yarn->blocked()) {
            return $this->refused($instance->id, $yarn->errorCode ?? 'dependencies.unsupported_format');
        }

        $composerInspection = $this->composer->inspect($instance, $cancelled);
        if ($composerInspection->blocked()) {
            return $this->refused($instance->id, $composerInspection->errorCode ?? 'dependencies.unreadable_source');
        }

        [$javascriptAction, $javascriptInspection] = $this->javascript($instance, $cancelled);
        if ($javascriptInspection->blocked()) {
            return $this->refused($instance->id, $javascriptInspection->errorCode ?? 'dependencies.unreadable_source');
        }

        $current = AppInstance::query()->with('node')->find($instance->id);
        if ($current === null || ! $this->available($current)) {
            return $this->refused($instance->id, 'dependencies.instance_unavailable');
        }

        $composer = DependencyUpdateStepResult::absent(DependencyEcosystem::Composer);
        if ($composerInspection->present) {
            $composer = $this->composer->apply($current, $cancelled);
            if (! $composer->completed()) {
                return $this->result($current, $composer, DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm));
            }
        }

        $current = AppInstance::query()->with('node')->find($instance->id);
        if ($current === null || ! $this->available($current)) {
            return $this->result($instance, $composer, DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm));
        }

        $javascript = DependencyUpdateStepResult::absent(DependencyEcosystem::Npm);
        if ($javascriptInspection->present) {
            if ($javascriptAction === null || $javascriptInspection->toolPath === null) {
                $javascript = DependencyUpdateStepResult::failed(DependencyEcosystem::Npm, 'dependencies.unsupported_delegation', false);
            } else {
                $javascript = $javascriptAction->apply($current, $javascriptInspection->toolPath, $cancelled);
            }
        }

        return $this->result($current, $composer, $javascript);
    }

    /**
     * @param  (Closure(): bool)|null  $cancelled
     * @return array{0: UpdateNpmDependenciesAction|UpdatePnpmDependenciesAction|UpdateBunDependenciesAction|null, 1: DependencyUpdateInspection}
     */
    private function javascript(AppInstance $instance, ?Closure $cancelled): array
    {
        $adapters = [$this->npm, $this->pnpm, $this->bun];
        $selected = null;
        $inspection = DependencyUpdateInspection::absent();
        foreach ($adapters as $adapter) {
            $candidate = $adapter->inspect($instance, $cancelled);
            if ($candidate->blocked()) {
                return [$adapter, $candidate];
            }
            if (! $candidate->present) {
                continue;
            }
            if ($selected !== null) {
                return [$adapter, DependencyUpdateInspection::failed('dependencies.ambiguous_manager')];
            }
            $selected = $adapter;
            $inspection = $candidate;
        }

        return [$selected, $inspection];
    }

    private function result(AppInstance $instance, DependencyUpdateStepResult $composer, DependencyUpdateStepResult $javascript): InstanceDependencyUpdateResult
    {
        return new InstanceDependencyUpdateResult($instance->id, $composer, $javascript, $this->scan->execute($instance));
    }

    private function refused(int $instanceId, string $errorCode): InstanceDependencyUpdateResult
    {
        return new InstanceDependencyUpdateResult(
            $instanceId,
            DependencyUpdateStepResult::notRun(DependencyEcosystem::Composer),
            DependencyUpdateStepResult::notRun(DependencyEcosystem::Npm),
            null,
            $errorCode,
        );
    }

    private function available(?AppInstance $instance): bool
    {
        return $instance !== null && $instance->status === AppInstanceState::Active
            && ! $instance->migration_required && ! $instance->removalMember()->exists();
    }
}
