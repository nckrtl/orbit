<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Dependencies\CollectedDependencyFiles;
use App\Domain\AppInstances\Dependencies\DependencyCollectionException;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Domain\AppInstances\Dependencies\InstanceDependencyScanResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ScanInstanceDependenciesAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppDevSourceOperationLock $sourceOperations,
        private CollectInstanceDependencyFilesAction $collect,
        private SelectDependencyInputAction $select,
        private ReadComposerDependencyGraphAction $composer,
        private ReadNpmDependencyGraphAction $npm,
        private ReadPnpmDependencyGraphAction $pnpm,
        private ReadBunDependencyGraphAction $bun,
        private PublishInstanceDependencyScanAction $publish,
        private ReadInstanceDependencyScanAction $read,
    ) {}

    public function execute(AppInstance $instance): InstanceDependencyScanResult
    {
        $attemptedAt = now()->toDateTimeImmutable();

        try {
            return $this->operations->run([$instance->id], function () use ($instance, $attemptedAt): InstanceDependencyScanResult {
                $current = AppInstance::query()->with('node')->find($instance->id);
                if ($current === null) {
                    return $this->failure($instance->id, $attemptedAt, 'dependencies.instance_unavailable', false);
                }

                return $current->environment === 'development'
                    ? $this->sourceOperations->synchronized($current->node_id, fn (): InstanceDependencyScanResult => $this->scan($current, $attemptedAt))
                    : $this->scan($current, $attemptedAt);
            });
        } catch (ResourceOperationException) {
            // A contender must not overwrite a newer attempt from the current owner.
            return $this->failure($instance->id, $attemptedAt, 'dependencies.operation_busy', false);
        }
    }

    private function scan(AppInstance $instance, DateTimeImmutable $attemptedAt): InstanceDependencyScanResult
    {
        $identity = $this->identity($instance);
        $current = AppInstance::query()->with('node')->find($instance->id);
        if (! $this->available($current)) {
            return $this->failure($instance->id, $attemptedAt, 'dependencies.instance_unavailable');
        }
        if ($identity !== $this->identity($current)) {
            return $this->failure($instance->id, $attemptedAt, 'dependencies.source_changed');
        }

        try {
            $files = $this->collect->execute($current);
            $composer = $this->parse($files, DependencyEcosystem::Composer, $attemptedAt);
            $javascript = $this->parse($files, DependencyEcosystem::Npm, $attemptedAt);
            $confirmed = $this->collect->execute($current);
            if ($files != $confirmed) {
                throw new DependencyCollectionException('dependencies.source_changed');
            }
        } catch (DependencyCollectionException $exception) {
            return $this->failure($instance->id, $attemptedAt, $exception->errorCode);
        }

        return new InstanceDependencyScanResult(
            $instance->id,
            $this->publishCurrent($instance->id, $identity, $composer),
            $this->publishCurrent($instance->id, $identity, $javascript),
        );
    }

    private function parse(CollectedDependencyFiles $files, DependencyEcosystem $ecosystem, DateTimeImmutable $attemptedAt): DependencyScanResult
    {
        try {
            $input = $this->select->execute($files, $ecosystem);
            $graph = $input->manifest === null || $input->lockfile === null ? null : match ($input->manager) {
                'composer' => $this->composer->execute($input->manifest, $input->lockfile),
                'npm' => $this->npm->execute($input->manifest, $input->lockfile),
                'pnpm' => $this->pnpm->execute($input->manifest, $input->lockfile),
                'bun' => $this->bun->execute($input->manifest, $input->lockfile),
                default => throw new DependencyCollectionException('dependencies.unsupported_format'),
            };

            return DependencyScanResult::refreshed(new DependencySnapshot($ecosystem, $input->source, $attemptedAt, $graph));
        } catch (DependencyCollectionException|DependencyParseException $exception) {
            return DependencyScanResult::failed($ecosystem, $attemptedAt, $exception->errorCode);
        }
    }

    /** @param array<string, mixed> $identity */
    private function publishCurrent(int $instanceId, array $identity, DependencyScanResult $result): DependencyScanResult
    {
        return DB::transaction(function () use ($instanceId, $identity, $result): DependencyScanResult {
            $current = AppInstance::query()->with('node')->lockForUpdate()->find($instanceId);
            if (! $this->available($current)) {
                $result = DependencyScanResult::failed($result->ecosystem, $result->attemptedAt, 'dependencies.instance_unavailable');
            } elseif ($identity !== $this->identity($current)) {
                $result = DependencyScanResult::failed($result->ecosystem, $result->attemptedAt, 'dependencies.source_changed');
            }

            return $this->publish->execute($instanceId, $result);
        });
    }

    private function available(?AppInstance $instance): bool
    {
        return $instance !== null && $instance->status === AppInstanceState::Active
            && ! $instance->migration_required && ! $instance->removalMember()->exists();
    }

    /** @return array<string, mixed> */
    private function identity(AppInstance $instance): array
    {
        return [
            ...$instance->only(['app_id', 'node_id', 'environment', 'source_layout', 'checkout_path', 'production_user', 'production_home', 'migration_required']),
            'node' => $instance->node->only(['wireguard_ip', 'user', 'status']),
        ];
    }

    private function failure(int $instanceId, DateTimeImmutable $attemptedAt, string $code, bool $record = true): InstanceDependencyScanResult
    {
        $results = [];
        foreach ([DependencyEcosystem::Composer, DependencyEcosystem::Npm] as $ecosystem) {
            $result = DependencyScanResult::failed($ecosystem, $attemptedAt, $code);
            $results[] = $record ? $this->publish->execute($instanceId, $result) : DependencyScanResult::failed(
                $ecosystem, $attemptedAt, $code, $this->read->execute($instanceId, $ecosystem)?->snapshot,
            );
        }

        return new InstanceDependencyScanResult($instanceId, $results[0], $results[1]);
    }
}
