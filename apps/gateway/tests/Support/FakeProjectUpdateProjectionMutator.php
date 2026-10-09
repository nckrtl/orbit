<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Project;
use App\Models\Route;

final class FakeProjectUpdateProjectionMutator implements ProjectUpdateProjectionMutator
{
    /** @var list<array{instance_id: int, url: string}> */
    public array $laravelUrls = [];

    /** @var list<array{instance_id: int, validated: bool, preserved_tuning: bool}> */
    public array $runtimeProjections = [];

    /** @var array<int, string> */
    public array $localTuning = [];

    public bool $refuseSlug = false;

    public bool $failSlugPrepare = false;

    public bool $applicationErrorOnUrl = false;

    public function preflightSlug(Project $project, string $newSlug): array
    {
        if ($this->refuseSlug) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_conflict',
                message: 'A generated Route domain would collide.',
                status: 409,
            );
        }

        $routes = [];

        foreach ($project->routes()->with('targets.instance')->orderBy('id')->get() as $route) {
            if ($route->provenance !== RouteProvenance::Generated) {
                continue;
            }

            $target = $route->targets->first()?->instance;
            $routes[] = [
                'route_id' => $route->id,
                'previous_domain' => $route->domain,
                'proposed_domain' => $this->proposedDomain($project, $route, $target, $newSlug),
                'instance_id' => $target?->id,
            ];
        }

        foreach ($routes as $proposal) {
            $owner = Route::query()
                ->where('domain', $proposal['proposed_domain'])
                ->whereKeyNot($proposal['route_id'])
                ->first();

            if ($owner instanceof Route) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: "Route domain [{$proposal['proposed_domain']}] would collide.",
                    status: 409,
                );
            }
        }

        return ['routes' => $routes];
    }

    public function prepareSlug(Project $project, string $newSlug, array $inventory): array
    {
        if ($this->failSlugPrepare) {
            throw new ResourceOperationException(
                errorCode: 'project.slug_prepare_failed',
                message: 'Preparing generated Route replacements failed.',
                status: 409,
            );
        }

        $prepared = [];

        foreach ($inventory['routes'] ?? [] as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $current = Route::query()->with('targets')->find((int) $proposal['route_id']);

            if (! $current instanceof Route) {
                continue;
            }

            $replacement = Route::query()->create([
                'project_id' => $current->project_id,
                'node_id' => $current->node_id,
                'cluster_id' => $current->cluster_id,
                'generation_basis_node_id' => $current->generation_basis_node_id,
                'domain' => $proposal['proposed_domain'],
                'provenance' => $current->provenance,
                'publication' => $current->publication,
                'status' => RouteStatus::Pending,
                'replaces_route_id' => $current->id,
                'replacement_step' => RouteReplacementStep::Reserved,
            ]);

            $current->update(['replaced_by_route_id' => $replacement->id]);

            foreach ($current->targets as $target) {
                $replacement->targets()->create([
                    'instance_id' => $target->instance_id,
                    'position' => $target->position,
                ]);
            }

            $env = $this->captureEnvironment((int) ($proposal['instance_id'] ?? 0));
            $prepared[] = [
                ...$proposal,
                'replacement_id' => $replacement->id,
                'previous_env' => $env,
            ];
        }

        return ['routes' => $prepared];
    }

    public function publishSlug(Project $project, string $newSlug, array $prepared): void
    {
        foreach ($prepared['routes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $replacement = Route::query()->find((int) $row['replacement_id']);
            $current = Route::query()->find((int) $row['route_id']);

            $instanceId = (int) ($row['instance_id'] ?? 0);

            if ($instanceId > 0) {
                $url = 'https://'.$row['proposed_domain'];
                $this->laravelUrls[] = ['instance_id' => $instanceId, 'url' => $url];
                $this->writeEnvironment($instanceId, $url);

                if ($this->applicationErrorOnUrl) {
                    throw new ResourceOperationException(
                        errorCode: 'project.slug_projection_failed',
                        message: "Project slug projection failed for Instance [{$instanceId}].",
                        status: 409,
                    );
                }

                $this->projectRuntime($instanceId);
            }

            if ($current instanceof Route) {
                $current->update(['status' => RouteStatus::Retiring]);
            }

            if ($replacement instanceof Route) {
                $replacement->update([
                    'status' => RouteStatus::Activating,
                    'replacement_step' => RouteReplacementStep::DatabaseCutover,
                ]);
            }

            if ($current instanceof Route) {
                $current->targets()->delete();
                $current->delete();
            }

            if ($replacement instanceof Route) {
                $replacement->update([
                    'status' => RouteStatus::Active,
                    'replaces_route_id' => null,
                    'replacement_step' => null,
                ]);
            }
        }
    }

    public function rollbackSlug(Project $project, array $prepared): void
    {
        foreach ($prepared['routes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $replacement = Route::query()->find((int) ($row['replacement_id'] ?? 0));
            $current = Route::query()->find((int) ($row['route_id'] ?? 0));

            if ($current instanceof Route) {
                $current->update(['replaced_by_route_id' => null]);
            }

            if ($replacement instanceof Route) {
                $replacement->targets()->delete();
                $replacement->delete();
            }

            $instanceId = (int) ($row['instance_id'] ?? 0);
            $previous = $row['previous_env'] ?? null;

            if ($instanceId > 0 && is_string($previous)) {
                $this->writeEnvironment($instanceId, $previous);
            }
        }
    }

    private function proposedDomain(Project $project, Route $route, ?Instance $instance, string $newSlug): string
    {
        $name = $instance?->name ?? 'default';

        if ($name === 'default' && str_starts_with($route->domain, $project->slug.'.')) {
            return $newSlug.substr($route->domain, strlen($project->slug));
        }

        $prefix = $name.'.'.$project->slug.'.';

        if (str_starts_with($route->domain, $prefix)) {
            return $name.'.'.$newSlug.'.'.substr($route->domain, strlen($prefix));
        }

        return str_replace($project->slug, $newSlug, $route->domain);
    }

    private function captureEnvironment(int $instanceId): ?string
    {
        if ($instanceId < 1) {
            return null;
        }

        $value = InstanceEnvironmentValue::query()
            ->where('instance_id', $instanceId)
            ->where('env_key', 'APP_URL')
            ->first();

        return $value instanceof InstanceEnvironmentValue ? $value->env_value : null;
    }

    private function writeEnvironment(int $instanceId, string $url): void
    {
        $value = InstanceEnvironmentValue::query()
            ->where('instance_id', $instanceId)
            ->where('env_key', 'APP_URL')
            ->first();

        if ($value instanceof InstanceEnvironmentValue) {
            $value->update(['env_value' => $url]);

            return;
        }

        InstanceEnvironmentValue::query()->create([
            'instance_id' => $instanceId,
            'env_key' => 'APP_URL',
            'env_value' => $url,
        ]);
    }

    private function projectRuntime(int $instanceId): void
    {
        $this->localTuning[$instanceId] ??= 'operator-local.conf';
        $this->runtimeProjections[] = [
            'instance_id' => $instanceId,
            'validated' => true,
            'preserved_tuning' => $this->localTuning[$instanceId] === 'operator-local.conf',
        ];
    }
}
