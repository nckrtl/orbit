<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Models\AppInstance;
use App\Models\AppInstanceDependencyScanAttempt;
use App\Models\DependencyPackage;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class PublishInstanceDependencyScanAction
{
    public function __construct(private ReadInstanceDependencyScanAction $read) {}

    public function execute(int $instanceId, DependencyScanResult $result): DependencyScanResult
    {
        try {
            return DB::transaction(function () use ($instanceId, $result): DependencyScanResult {
                $instance = AppInstance::query()->lockForUpdate()->find($instanceId);

                if ($instance === null) {
                    return DependencyScanResult::failed($result->ecosystem, $result->attemptedAt, 'dependencies.instance_unavailable');
                }

                if ($instance->status === AppInstanceState::Removing || $instance->removalMember()->exists()) {
                    $result = DependencyScanResult::failed($result->ecosystem, $result->attemptedAt, 'dependencies.instance_unavailable');
                }

                if ($result->succeeded() && $result->snapshot !== null) {
                    $this->replace($instance, $result->snapshot);
                }

                AppInstanceDependencyScanAttempt::query()->create([
                    'app_instance_id' => $instance->id,
                    'ecosystem' => $result->ecosystem,
                    'attempted_at' => $result->attemptedAt->setTimezone(new DateTimeZone('UTC')),
                    'error_code' => $result->errorCode,
                ]);

                return $this->read->execute($instance->id, $result->ecosystem)
                    ?? throw new LogicException('A recorded dependency attempt must have an outcome.');
            });
        } catch (QueryException|InvalidArgumentException $exception) {
            if (! $result->succeeded()) {
                throw $exception;
            }

            // The replacement transaction has rolled back before its failure is recorded.
            return $this->execute($instanceId, DependencyScanResult::failed(
                $result->ecosystem,
                $result->attemptedAt,
                'dependencies.persistence_failed',
            ));
        }
    }

    private function replace(AppInstance $instance, DependencySnapshot $snapshot): void
    {
        $instance->dependencyObservations()->where('ecosystem', $snapshot->ecosystem)->delete();
        $observation = $instance->dependencyObservations()->create([
            'ecosystem' => $snapshot->ecosystem,
            'present' => $snapshot->graph !== null,
            'observed_at' => $snapshot->observedAt->setTimezone(new DateTimeZone('UTC')),
            'project_root' => $snapshot->source->projectRoot,
            'source_reference' => $snapshot->source->reference,
            'file_hashes' => $snapshot->source->fileHashes,
            'format' => $snapshot->source->format,
        ]);
        $ids = [];

        foreach ($snapshot->graph->resolutions ?? [] as $resolution) {
            $package = DependencyPackage::query()->firstOrCreate([
                'ecosystem' => $resolution->package->ecosystem,
                'name' => $resolution->package->name,
            ]);
            $record = $observation->resolutions()->create([
                'dependency_package_id' => $package->id,
                'ecosystem' => $snapshot->ecosystem,
                'locator' => $resolution->id,
                'version' => $resolution->version,
                'regular' => $resolution->regular,
                'development' => $resolution->development,
                'source_reference' => $resolution->sourceReference,
                'integrity' => $resolution->integrity,
            ]);
            $ids[$resolution->id] = $record->id;
        }

        foreach ($snapshot->graph->requirements ?? [] as $requirement) {
            $observation->edges()->create([
                'from_resolution_id' => $requirement->from === null ? null : $ids[$requirement->from],
                'to_resolution_id' => $requirement->to === null ? null : $ids[$requirement->to],
                'name' => $requirement->name,
                'constraint' => $requirement->constraint,
                'kind' => $requirement->kind,
                'scope' => $requirement->scope,
                'optional' => $requirement->optional,
            ]);
        }
    }
}
