<?php

declare(strict_types=1);

namespace App\Http\Authorization;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Tool;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final readonly class ServingNodeResolver
{
    /** @return list<Node> */
    public function resolve(Request $request, ServingNode $scope): array
    {
        return match ($scope) {
            ServingNode::Gateway => $this->gateway(),
            ServingNode::Target => $this->target($request),
            ServingNode::AppOwning => $this->appOwning($request),
            ServingNode::InstanceOwning => $this->instanceOwning($request),
            ServingNode::EnvironmentInstanceOwning => $this->environmentInstanceOwning($request),
            ServingNode::WorkspaceOwning => $this->workspaceOwning($request),
            ServingNode::ProcessOwning => $this->processOwning($request),
            ServingNode::ScheduleOwning => $this->scheduleOwning($request),
            ServingNode::ScheduleHost => $this->scheduleHost($request),
            ServingNode::ToolOwning => $this->toolOwning($request),
            ServingNode::ClusterOwning => $this->clusterOwning($request),
            ServingNode::RouteOwning => $this->routeOwning($request),
            ServingNode::RoleMutation => $this->roleMutation($request),
            ServingNode::Collection => [],
            ServingNode::Caller => $this->caller($request),
        };
    }

    /** @return list<Node> */
    private function caller(Request $request): array
    {
        $caller = $request->user();

        return $caller instanceof Node ? [$caller] : [];
    }

    /** @return list<Node> */
    private function gateway(): array
    {
        $gateways = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static function ($query): void {
                $query
                    ->where('role', RoleName::Gateway)
                    ->where('status', LifecycleStatus::Active);
            })
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($gateways->count() !== 1) {
            throw new ActiveGatewayMissing('Exactly one active Gateway node is required.');
        }

        return [$gateways->sole()];
    }

    /** @return list<Node> */
    private function target(Request $request): array
    {
        foreach (['node', 'servingNode'] as $parameter) {
            $node = $request->route($parameter);

            if ($node instanceof Node) {
                return [$node];
            }
        }

        return [];
    }

    /** @return list<Node> */
    private function appOwning(Request $request): array
    {
        $app = $request->route('app');

        if (! $app instanceof OrbitApp) {
            $appId = $this->positiveInteger($request->input('app_id'));

            if ($appId === null) {
                return [];
            }

            $app = OrbitApp::query()->findOrFail($appId);
        }

        /** @var list<Node> $nodes */
        $nodes = Node::query()
            ->where(function ($query) use ($app): void {
                $query
                    ->whereIn('id', $app->instances()->select('node_id'))
                    ->orWhereIn('id', $app->appInstances()->select('node_id'));
            })
            ->orderBy('id')
            ->get()
            ->all();

        if ($nodes !== []) {
            return $nodes;
        }

        return $this->gateway();
    }

    /** @return list<Node> */
    private function instanceOwning(Request $request): array
    {
        $instance = $request->route('instance');

        if ($instance instanceof AppInstance || $instance instanceof Instance) {
            return [Node::query()->findOrFail($instance->node_id)];
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId === null) {
            return [];
        }

        return [Node::query()->findOrFail($nodeId)];
    }

    /** @return list<Node> */
    private function environmentInstanceOwning(Request $request): array
    {
        $target = $request->route('instance');

        if ($target instanceof AppInstance) {
            return [Node::query()->findOrFail($target->node_id)];
        }

        if (! is_string($target) || $target === '') {
            return [];
        }

        $numeric = $this->positiveInteger($target);

        if ($numeric !== null) {
            $instances = AppInstance::query()->whereKey($numeric)->limit(2)->get();
        } else {
            $instances = AppInstance::query()
                ->whereHas('routes', static fn ($query) => $query->where('hostname', $target))
                ->orderBy('id')
                ->limit(2)
                ->get();
        }

        if ($instances->count() > 1) {
            throw new ResourceOperationException(
                errorCode: 'env.target_ambiguous',
                message: 'The environment target matches multiple AppInstances.',
                status: 409,
            );
        }

        if ($instances->isEmpty()) {
            throw new ModelNotFoundException()->setModel(AppInstance::class, [$target]);
        }

        $instance = $instances->sole();
        $request->route()?->setParameter('instance', $instance);

        return [Node::query()->findOrFail($instance->node_id)];
    }

    /** @return list<Node> */
    private function workspaceOwning(Request $request): array
    {
        $workspace = $request->route('workspace');

        if ($workspace instanceof Workspace) {
            $instance = Instance::query()->findOrFail($workspace->instance_id);

            return [Node::query()->findOrFail($instance->node_id)];
        }

        $instanceId = $this->positiveInteger($request->input('instance_id'));

        if ($instanceId === null) {
            return [];
        }

        $instance = Instance::query()->findOrFail($instanceId);

        return [Node::query()->findOrFail($instance->node_id)];
    }

    /**
     * @return list<Node>
     */
    private function processOwning(Request $request): array
    {
        $process = $request->route('process');

        if ($process instanceof Process) {
            if ($process->owner_type !== AppInstance::class) {
                throw new ResourceOperationException(
                    errorCode: 'process.target_unsupported',
                    message: 'The Process owner is not a supported AppInstance.',
                    status: 409,
                );
            }

            return [Node::query()->findOrFail(
                AppInstance::query()->findOrFail($process->owner_id)->node_id,
            )];
        }

        $targetType = $request->input('target_type');
        $targetId = $this->positiveInteger($request->input('target_id'));

        if (! is_string($targetType) || $targetId === null) {
            return [];
        }

        $owner = match ($targetType) {
            'instance' => AppInstance::query()->findOrFail($targetId),
            default => null,
        };

        if (! $owner instanceof AppInstance) {
            return [];
        }

        return [Node::query()->findOrFail($owner->node_id)];
    }

    /** @return list<Node> */
    private function scheduleHost(Request $request): array
    {
        $schedule = $request->route('schedule');

        if ($schedule instanceof Schedule) {
            return [Node::query()->findOrFail($schedule->host_node_id)];
        }

        if (! is_string($schedule) || $schedule === '') {
            return [];
        }

        $schedule = Schedule::query()->find($schedule);

        if (! $schedule instanceof Schedule) {
            return [];
        }

        return [Node::query()->findOrFail($schedule->host_node_id)];
    }

    /** @return list<Node> */
    private function scheduleOwning(Request $request): array
    {
        $schedule = $request->route('schedule');

        if ($schedule instanceof Schedule) {
            return match ($schedule->target_type) {
                Node::class => [Node::query()->findOrFail($schedule->target_id)],
                AppInstance::class => [Node::query()->findOrFail(
                    AppInstance::query()->findOrFail($schedule->target_id)->node_id,
                )],
                default => [],
            };
        }

        $targetId = $this->positiveInteger($request->input('target_id'));

        if ($targetId === null) {
            return [];
        }

        return match ($request->input('target_type')) {
            'node' => [Node::query()->findOrFail($targetId)],
            'instance' => [Node::query()->findOrFail(
                AppInstance::query()->findOrFail($targetId)->node_id,
            )],
            default => [],
        };
    }

    /** @return list<Node> */
    private function toolOwning(Request $request): array
    {
        $tool = $request->route('tool');

        if ($tool instanceof Tool) {
            return [Node::query()->findOrFail($tool->node_id)];
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId === null) {
            return [];
        }

        return [Node::query()->findOrFail($nodeId)];
    }

    /** @return list<Node> */
    private function clusterOwning(Request $request): array
    {
        $cluster = $request->route('cluster');

        if (! $cluster instanceof Cluster) {
            return [];
        }

        /** @var list<Node> $nodes */
        $nodes = $cluster->nodes()->orderBy('id')->get()->all();

        return $nodes !== [] ? $nodes : $this->gateway();
    }

    /** @return list<Node> */
    private function routeOwning(Request $request): array
    {
        $route = $request->route('route');

        if ($route instanceof Route) {
            if ($route->node_id !== null) {
                return [Node::query()->findOrFail($route->node_id)];
            }

            return $this->clusterNodes((int) $route->cluster_id);
        }

        $appInstanceId = $this->positiveInteger($request->input('app_instance_id'));

        if ($appInstanceId !== null) {
            $appInstance = AppInstance::query()->findOrFail($appInstanceId);

            return [Node::query()->findOrFail($appInstance->node_id)];
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId !== null) {
            return [Node::query()->findOrFail($nodeId)];
        }

        $clusterId = $this->positiveInteger($request->input('cluster_id'));

        return $clusterId === null ? [] : $this->clusterNodes($clusterId);
    }

    /** @return list<Node> */
    private function clusterNodes(int $clusterId): array
    {
        $cluster = Cluster::query()->findOrFail($clusterId);
        /** @var list<Node> $nodes */
        $nodes = $cluster->nodes()->orderBy('id')->get()->all();

        return $nodes !== [] ? $nodes : $this->gateway();
    }

    /**
     * @return list<Node>
     */
    private function roleMutation(Request $request): array
    {
        $role = $request->route('role') ?? $request->input('role');

        if ($role === RoleName::Metrics->value || $role === RoleName::Metrics) {
            return $this->gateway();
        }

        return $this->target($request);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }
}
