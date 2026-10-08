<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Projects\ProjectApps;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;

/** Internal serving readers only. Ordinary Instance configuration remains published state. */
final readonly class CommittedAppServingView
{
    /** @return array{configuration: array{name: string, path: string, web_root: ?string, type: string}, runtime: array{php_version: ?string, laravel: ?bool, vite_port: ?int, agentation_port: ?int, annotator_port: ?int}, scoped: bool}|null */
    public function app(Instance $instance, ?string $app): ?array
    {
        $app ??= $instance->appConfiguration()['name'];
        $projection = InstanceAppProjection::query()->where('active_instance_id', $instance->id)->first();
        if (! $projection instanceof InstanceAppProjection) {
            return ['configuration' => $instance->appConfiguration($app), 'runtime' => $instance->runtimeForApp($app), 'scoped' => $instance->usesAppRuntimeIdentity($app)];
        }
        if ($projection->node_id !== $instance->node_id || AppProjectionIdentity::digest($projection->plan) !== $projection->plan_digest) {
            throw new ResourceOperationException('app.projection_receipt_conflict', 'The committed serving plan does not match its Instance.', 409);
        }
        $step = InstanceAppProjectionStep::query()->where('instance_app_projection_id', $projection->id)
            ->where('intent->resource', 'serving')->where('intent->app', $app)->orderByDesc('sequence')->first();
        $candidate = $step instanceof InstanceAppProjectionStep && (($step->intent['phase'] ?? null) === 'cleanup'
            || ($step->intent['phase'] ?? null) === 'prepare' && in_array($step->status, ['rendering', 'complete'], true));
        $side = $candidate ? 'candidate' : 'before';
        $configuration = $this->configuration($projection, $side, $app);
        if (! is_array($configuration) || ! ProjectApps::isServing($configuration) || $instance->task_workspace_routed === false) {
            return null;
        }
        $profile = $this->profile($projection, $side, $app);
        $retained = $instance->app_runtime[$app] ?? [];
        $runtime = [...['vite_port' => null, 'agentation_port' => null, 'annotator_port' => null], ...$retained, ...$profile];
        /** @var array{php_version: ?string, laravel: ?bool, vite_port: ?int, agentation_port: ?int, annotator_port: ?int} $runtime */

        return ['configuration' => $configuration, 'runtime' => $runtime,
            'scoped' => ($this->resources($projection)[$app]['app_identity'] ?? true) === true];
    }

    /**
     * Pending addition Routes remain private; only native serving readers include them.
     *
     * @return list<int>
     */
    public function candidateRouteIds(): array
    {
        $ids = [];
        $steps = InstanceAppProjectionStep::query()
            ->whereIn('instance_app_projection_id', InstanceAppProjection::query()->whereNotNull('active_instance_id')->select('id'))
            ->where('intent->resource', 'serving')->whereIn('status', ['rendering', 'complete'])->get();
        foreach ($steps as $step) {
            $targets = $step->intent['targets'] ?? [];
            $id = is_array($targets) ? ($targets['route_id'] ?? null) : null;
            if (is_string($id) && ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array{name: string, path: string, web_root: ?string, type: string}|null */
    public function configuration(InstanceAppProjection $projection, string $side, string $app): ?array
    {
        $apps = $projection->plan[$side.'_apps'] ?? [];
        $value = is_array($apps) ? ($apps[$app] ?? null) : null;

        return $value === null ? null : ProjectApps::validate([$value])[0];
    }

    /** @return array{php_version: ?string, laravel: bool} */
    public function profile(InstanceAppProjection $projection, string $side, string $app): array
    {
        $profiles = $projection->plan[$side.'_profiles'] ?? [];
        $profile = is_array($profiles) ? ($profiles[$app] ?? []) : [];
        if (! is_array($profile) || ! is_bool($profile['laravel'] ?? null) || ! (is_string($profile['php_version'] ?? null) || ($profile['php_version'] ?? null) === null)) {
            throw new ResourceOperationException('app.projection_receipt_conflict', 'The frozen source profile is invalid.', 409);
        }

        return ['php_version' => $profile['php_version'] ?? null, 'laravel' => $profile['laravel']];
    }

    /** @return array<string, array{route_id: int, domain: string, app_identity: bool}> */
    public function resources(InstanceAppProjection $projection): array
    {
        $resources = $projection->plan['resources'] ?? [];
        $serving = is_array($resources) ? ($resources['serving'] ?? []) : [];
        if (! is_array($serving)) {
            throw new ResourceOperationException('app.projection_receipt_conflict', 'The frozen serving resources are invalid.', 409);
        }
        $validated = [];
        foreach ($serving as $app => $resource) {
            if (! is_string($app) || ! is_array($resource) || ! is_int($resource['route_id'] ?? null) || ! is_string($resource['domain'] ?? null) || ! is_bool($resource['app_identity'] ?? null)) {
                throw new ResourceOperationException('app.projection_receipt_conflict', 'The frozen serving resource is invalid.', 409);
            }
            $validated[$app] = ['route_id' => $resource['route_id'], 'domain' => $resource['domain'], 'app_identity' => $resource['app_identity']];
        }

        return $validated;
    }
}
