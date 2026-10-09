<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Compute\ComputeException;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Compute\ProjectSandboxInstanceRemoval;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Route;
use App\Models\Task;

/** Native runtime work may target one enrolled Project guest. */
final readonly class ProjectSandboxRuntimeGuard
{
    public static function assertRuntime(Instance $instance, ?Route $route = null): void
    {
        if (! InstanceSandboxGuard::isSandbox($instance)) {
            return;
        }
        self::assertOwned($instance);
        $sandbox = $instance->taskSandbox;
        if ($sandbox === null) {
            self::refuse();
        }
        try {
            app(SandboxFleetIdentity::class)->assertReady($sandbox, $instance->node);
        } catch (ComputeException) {
            self::refuse();
        }
        foreach ($instance->routes()->get() as $associated) {
            self::assertRoute($instance, $associated);
        }
        if ($route !== null) {
            self::assertRoute($instance, $route);
        }
    }

    public static function assertOwned(Instance $instance): void
    {
        $instance->load(['taskSandbox.group.project', 'node', 'project']);
        $sandbox = $instance->taskSandbox;
        $group = $sandbox?->group;
        if ($sandbox === null || $group === null || $group->parent_id !== null
            || $group->task_compute !== TaskCompute::Vm || $group->execution_mode !== TaskExecutionMode::Managed
            || $group->project->slug === 'orbit' || ! in_array($sandbox->provider, ['incus', 'upcloud'], true)
            || $instance->project_id !== $group->project_id || $instance->node_id !== $sandbox->node_id
            || $group->taskable_id !== $instance->id || $group->taskable_type !== $instance->getMorphClass()
            || $instance->name !== TaskWorkspaceName::for($group) || $instance->branch_override !== $instance->name
            || $instance->checkout_path !== '/home/orbit/orbit' || $instance->source_layout !== InstanceSourceLayout::Checkout->value
            || ! is_bool($instance->task_workspace_routed)
            || Task::withoutGlobalScope('subtask')->where('taskable_type', $instance->getMorphClass())
                ->where('taskable_id', $instance->id)->whereKeyNot($group->id)->exists()) {
            self::refuse();
        }
        try {
            app(SandboxFleetIdentity::class)->assertOwned($sandbox, $instance->node);
        } catch (ComputeException) {
            self::refuse();
        }
    }

    public static function assertRoute(Instance $instance, Route $route, ?InstanceRemovalMember $removal = null): void
    {
        $withdrawn = false;
        if ($removal !== null) {
            ProjectSandboxInstanceRemoval::assertJournal($instance, $removal);
            $withdrawn = $removal->route_id === $route->id && $removal->source_prepared_at !== null
                && $route->targets()->count() === 0 && $instance->routeTargets()->count() === 0;
        }
        $state = app(RouteStateResolver::class);
        $placement = $state->forNode($instance->node);
        if ($instance->task_workspace_routed !== true || $route->project_id !== $instance->project_id
            || $route->kind !== RouteKind::App || $route->publication !== RoutePublication::Private
            || $route->provenance !== RouteProvenance::Generated || ! is_string($route->app)
            || $route->domain !== $state->generatedDomain($instance->project->slug, $instance->name, $placement->effectiveTld, $route->app)
            || $route->node_id !== $placement->nodeId || $route->cluster_id !== $placement->clusterId
            || $route->generation_basis_node_id !== $instance->node_id
            || $route->replaces_route_id !== null || $route->replaced_by_route_id !== null || $route->replacement_step !== null
            || (! $withdrawn && ($route->targets()->count() !== 1 || ! $route->targets()->where('instance_id', $instance->id)->exists()
                || $instance->routeTargets()->count() !== 1))) {
            self::refuse();
        }
    }

    private static function refuse(): never
    {
        throw new ResourceOperationException('instance.sandbox_managed', 'The Project sandbox runtime ownership is unavailable.', 409);
    }
}
