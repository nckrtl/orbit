<?php

declare(strict_types=1);

namespace App\Infrastructure\Projects;

use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRename;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class NativeProjectUpdateProjectionMutator implements ProjectUpdateProjectionMutator
{
    public function __construct(
        private RouteStateResolver $domains,
        private InstanceRouteEnvironmentSynchronizer $environment,
        private ConvergeRouteAction $routes,
    ) {}

    public function preflightSlug(Project $project, string $newSlug): array
    {
        InstanceRename::assertProjectAvailable($project);
        $routes = [];

        foreach ($project->routes()->with(['targets.instance.node', 'generationBasisNode'])->orderBy('id')->get() as $route) {
            if ($route->provenance !== RouteProvenance::Generated) {
                continue;
            }

            $target = $route->targets->first()?->instance;
            $proposed = $this->proposedDomain($project, $route, $target, $newSlug);
            $owner = Route::query()->where('domain', $proposed)->whereKeyNot($route->id)->first();

            if ($owner instanceof Route) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: "Route domain [{$proposed}] would collide.",
                    status: 409,
                );
            }

            $routes[] = [
                'route_id' => $route->id,
                'app' => $route->app,
                'previous_domain' => $route->domain,
                'proposed_domain' => $proposed,
                'instance_id' => $target?->id,
            ];
        }

        return ['routes' => $routes];
    }

    public function prepareSlug(Project $project, string $newSlug, array $inventory): array
    {
        InstanceRename::assertProjectAvailable($project);
        $prepared = [];

        foreach ($this->rows($inventory['routes'] ?? null) as $proposal) {
            $current = Route::query()->with('targets')->find(StoredValue::integer($proposal['route_id'] ?? null));

            if (! $current instanceof Route) {
                continue;
            }

            $replacement = Route::query()->create([
                'project_id' => $current->project_id,
                'app' => $current->app,
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

            $prepared[] = [
                ...$proposal,
                'replacement_id' => $replacement->id,
                'previous_env' => $this->storedUrl(StoredValue::integer($proposal['instance_id'] ?? null), $current->app),
            ];
        }

        return ['routes' => $prepared];
    }

    public function publishSlug(Project $project, string $newSlug, array $prepared): void
    {
        InstanceRename::assertProjectAvailable($project);
        foreach ($this->rows($prepared['routes'] ?? null) as $row) {
            $replacement = Route::query()->with('targets.instance')->find(StoredValue::integer($row['replacement_id'] ?? null));
            $current = Route::query()->find(StoredValue::integer($row['route_id'] ?? null));
            $instance = Instance::query()->find(StoredValue::integer($row['instance_id'] ?? null));

            if (! $instance instanceof Instance) {
                $this->publishTargetlessSlugRoute($current, $replacement);

                continue;
            }

            $domain = is_string($row['proposed_domain'] ?? null)
                ? $row['proposed_domain']
                : $replacement?->domain;
            $app = $instance->appConfiguration(is_string($row['app'] ?? null) ? $row['app'] : $replacement?->app)['name'];
            $authoritative = $current ?? $instance->authoritativeRoute($app);

            if (! is_string($domain) || ! $authoritative instanceof Route) {
                throw new ResourceOperationException(
                    errorCode: 'project.slug_projection_failed',
                    message: "Project slug projection could not resolve a Route for Instance [{$instance->id}].",
                    status: 409,
                );
            }

            try {
                if ($instance->placementEnvironment() === 'development' && $replacement instanceof Route) {
                    $this->environment->synchronizeRouteDomain($instance, InstanceEnvironmentRouteDomain::Candidate, $app);
                }

                $this->routes->execute(
                    $authoritative,
                    $domain,
                    allowGenerated: true,
                );

                if ($instance->placementEnvironment() === 'development' && ! $replacement instanceof Route) {
                    $this->environment->synchronizeRouteDomain($instance, InstanceEnvironmentRouteDomain::Authoritative, $app);
                }
            } catch (Throwable $exception) {
                throw new ResourceOperationException(
                    errorCode: 'project.slug_projection_failed',
                    message: "Project slug projection failed for Instance [{$instance->id}].",
                    status: 409,
                    previous: $exception,
                );
            }
        }
    }

    /**
     * Targetless generated Routes have no Instance runtime to project. Preserve their replacement
     * as an ordinary pending Route, keeping its generation-basis Node but clearing replacement state.
     */
    private function publishTargetlessSlugRoute(?Route $current, ?Route $replacement): void
    {
        if (
            ! $current instanceof Route
            && $replacement instanceof Route
            && $replacement->status === RouteStatus::Pending
            && $replacement->replaces_route_id === null
            && $replacement->replacement_step === null
        ) {
            return;
        }

        if (! $current instanceof Route || ! $replacement instanceof Route) {
            throw new ResourceOperationException(
                errorCode: 'project.slug_projection_failed',
                message: 'A targetless generated Route replacement could not be resolved.',
                status: 409,
            );
        }

        DB::transaction(function () use ($current, $replacement): void {
            $lockedCurrent = Route::query()->lockForUpdate()->find($current->id);
            $lockedReplacement = Route::query()->lockForUpdate()->find($replacement->id);

            if (
                ! $lockedCurrent instanceof Route
                || ! $lockedReplacement instanceof Route
                || $lockedCurrent->replaced_by_route_id !== $lockedReplacement->id
                || $lockedReplacement->replaces_route_id !== $lockedCurrent->id
                || $lockedCurrent->targets()->exists()
                || $lockedReplacement->targets()->exists()
            ) {
                throw new ResourceOperationException(
                    errorCode: 'project.slug_projection_failed',
                    message: 'A targetless generated Route replacement changed before publication.',
                    status: 409,
                );
            }

            $lockedReplacement->update([
                'status' => RouteStatus::Pending,
                'replaces_route_id' => null,
                'replacement_step' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $lockedCurrent->delete();
        });
    }

    public function rollbackSlug(Project $project, array $prepared): void
    {
        InstanceRename::assertProjectAvailable($project);
        foreach ($this->rows($prepared['routes'] ?? null) as $row) {
            $replacement = Route::query()->find(StoredValue::integer($row['replacement_id'] ?? null));
            $current = Route::query()->find(StoredValue::integer($row['route_id'] ?? null));

            if ($current instanceof Route) {
                $current->update(['replaced_by_route_id' => null]);
            }

            if ($replacement instanceof Route) {
                $replacement->targets()->delete();
                $replacement->delete();
            }

            $instance = Instance::query()->find(StoredValue::integer($row['instance_id'] ?? null));
            $previous = $row['previous_env'] ?? null;

            if ($instance instanceof Instance && is_string($previous)) {
                $app = $instance->appConfiguration(is_string($row['app'] ?? null) ? $row['app'] : $current?->app)['name'];
                $this->writeStoredUrl($instance->id, $previous, $app);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $rows;
    }

    private function proposedDomain(Project $project, Route $route, ?Instance $instance, string $newSlug): string
    {
        $node = $instance instanceof Instance ? $instance->node : $route->generationBasisNode;

        if ($node instanceof Node) {
            $name = $instance instanceof Instance ? $instance->name : 'default';

            return $this->domains->generatedDomain($newSlug, $name, $this->domains->forNode($node)->effectiveTld, $route->app ?? throw new ResourceOperationException('app.not_found', 'A generated Route has no app association.', 409));
        }

        if (str_starts_with($route->domain, $project->slug.'.')) {
            return $newSlug.substr($route->domain, strlen($project->slug));
        }

        return str_replace($project->slug, $newSlug, $route->domain);
    }

    private function storedUrl(int $instanceId, ?string $app): ?string
    {
        if ($instanceId < 1) {
            return null;
        }

        $value = InstanceEnvironmentValue::query()
            ->where('instance_id', $instanceId)
            ->where('app', $app)
            ->where('env_key', 'APP_URL')
            ->first();

        return $value instanceof InstanceEnvironmentValue ? $value->env_value : null;
    }

    private function writeStoredUrl(int $instanceId, string $url, string $app): void
    {
        $value = InstanceEnvironmentValue::query()
            ->where('instance_id', $instanceId)
            ->where('app', $app)
            ->where('env_key', 'APP_URL')
            ->first();

        if ($value instanceof InstanceEnvironmentValue) {
            $value->update(['env_value' => $url]);

            return;
        }

        InstanceEnvironmentValue::query()->create([
            'instance_id' => $instanceId,
            'app' => $app,
            'env_key' => 'APP_URL',
            'env_value' => $url,
        ]);
    }
}
