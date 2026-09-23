<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\ProjectNodeExclusion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class DevelopmentNodeExclusion
{
    public function assertAvailable(OrbitApp $app, Node $node): void
    {
        if ($this->excludes($app, $node)) {
            throw new ResourceOperationException(
                'instance.node_excluded',
                "Project [{$app->slug}] cannot use Node [{$node->name}] for development.",
                409,
            );
        }
    }

    public function excludes(OrbitApp $app, Node $node): bool
    {
        return ProjectNodeExclusion::query()
            ->where('app_id', $app->id)
            ->where('node_id', $node->id)
            ->exists();
    }

    /** @return array{exclusion: ProjectNodeExclusion, created: bool} */
    public function add(OrbitApp $app, Node $node): array
    {
        if (! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException(
                'project.excluded_node_not_app_dev',
                "Node [{$node->name}] has no active app-dev role.",
                422,
            );
        }

        $created = false;
        $exclusion = DB::transaction(function () use ($app, $node, &$created): ProjectNodeExclusion {
            $existing = ProjectNodeExclusion::query()
                ->where('app_id', $app->id)
                ->where('node_id', $node->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ProjectNodeExclusion) {
                return $existing;
            }

            $created = true;

            return ProjectNodeExclusion::query()->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
            ]);
        });

        return ['exclusion' => $exclusion->load(['app', 'node']), 'created' => $created];
    }

    public function remove(OrbitApp $app, Node $node): ProjectNodeExclusion
    {
        $exclusion = ProjectNodeExclusion::query()
            ->where('app_id', $app->id)
            ->where('node_id', $node->id)
            ->first();

        if (! $exclusion instanceof ProjectNodeExclusion) {
            throw new ResourceOperationException(
                'project.excluded_node_missing',
                "Project [{$app->slug}] has no exclusion for Node [{$node->name}].",
                404,
            );
        }

        $exclusion->load(['app', 'node']);
        $exclusion->delete();

        return $exclusion;
    }

    /** @return Collection<int, ProjectNodeExclusion> */
    public function forProject(OrbitApp $app): Collection
    {
        return ProjectNodeExclusion::query()
            ->where('app_id', $app->id)
            ->with(['app', 'node'])
            ->orderBy('node_id')
            ->get();
    }

    /** @return Collection<int, ProjectNodeExclusion> */
    public function forNode(Node $node): Collection
    {
        return ProjectNodeExclusion::query()
            ->where('node_id', $node->id)
            ->with(['app', 'node'])
            ->orderBy('app_id')
            ->get();
    }
}
