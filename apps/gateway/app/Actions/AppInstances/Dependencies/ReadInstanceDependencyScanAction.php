<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyIdentity;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScanResult;
use App\Domain\AppInstances\Dependencies\DependencySnapshot;
use App\Domain\AppInstances\Dependencies\DependencySource;
use App\Models\AppInstanceDependencyObservation;
use App\Models\AppInstanceDependencyScanAttempt;
use Illuminate\Support\Facades\DB;

final readonly class ReadInstanceDependencyScanAction
{
    public function execute(int $instanceId, DependencyEcosystem $ecosystem): ?DependencyScanResult
    {
        return DB::transaction(function () use ($instanceId, $ecosystem): ?DependencyScanResult {
            $observation = AppInstanceDependencyObservation::query()
                ->where('app_instance_id', $instanceId)
                ->where('ecosystem', $ecosystem)
                ->first();
            $attempt = AppInstanceDependencyScanAttempt::query()
                ->where('app_instance_id', $instanceId)
                ->where('ecosystem', $ecosystem)
                ->latest('id')
                ->first();
            $snapshot = $observation === null ? null : $this->snapshot($observation);

            if ($attempt?->error_code !== null) {
                return DependencyScanResult::failed($ecosystem, $attempt->attempted_at, $attempt->error_code, $snapshot);
            }

            return $snapshot === null ? null : DependencyScanResult::refreshed($snapshot);
        });
    }

    private function snapshot(AppInstanceDependencyObservation $observation): DependencySnapshot
    {
        $graph = null;

        if ($observation->present) {
            $resolutions = [];
            $locators = [];
            foreach ($observation->resolutions()->with('package')->orderBy('id')->get() as $resolution) {
                $locators[$resolution->id] = $resolution->locator;
                $resolutions[] = new DependencyResolution(
                    $resolution->locator,
                    new DependencyIdentity($resolution->ecosystem, $resolution->package->name),
                    $resolution->version,
                    $resolution->regular,
                    $resolution->development,
                    $resolution->source_reference,
                    $resolution->integrity,
                );
            }

            $requirements = [];
            foreach ($observation->edges()->orderBy('id')->get() as $edge) {
                $requirements[] = new DependencyRequirement(
                    $edge->from_resolution_id === null ? null : $locators[$edge->from_resolution_id],
                    $edge->to_resolution_id === null ? null : $locators[$edge->to_resolution_id],
                    $edge->name,
                    $edge->constraint,
                    $edge->kind,
                    $edge->scope,
                    $edge->optional,
                );
            }

            $graph = new DependencyGraph($observation->ecosystem, $resolutions, $requirements);
        }

        return new DependencySnapshot(
            $observation->ecosystem,
            new DependencySource(
                $observation->project_root,
                $observation->source_reference,
                $observation->file_hashes,
                $observation->format,
            ),
            $observation->observed_at,
            $graph,
        );
    }
}
