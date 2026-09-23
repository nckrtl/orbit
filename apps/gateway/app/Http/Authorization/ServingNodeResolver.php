<?php

declare(strict_types=1);

namespace App\Http\Authorization;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDeployment;
use App\Models\Cluster;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\TaskGroup;
use App\Models\Tool;
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
            ServingNode::DeploymentOwning => $this->deploymentOwning($request),
            ServingNode::CandidateClone => $this->candidateClone($request),
            ServingNode::InstanceTransfer => $this->instanceTransfer($request),
            ServingNode::EnvironmentInstanceOwning => $this->environmentInstanceOwning($request),
            ServingNode::ProcessOwning => $this->processOwning($request),
            ServingNode::HerdrSessionOwning => $this->herdrSessionOwning($request),
            ServingNode::ScheduleOwning => $this->scheduleOwning($request),
            ServingNode::ScheduleHost => $this->scheduleHost($request),
            ServingNode::AppInstanceHost => $this->appInstanceHost($request),
            ServingNode::ToolOwning => $this->toolOwning($request),
            ServingNode::ClusterOwning => $this->clusterOwning($request),
            ServingNode::RouteOwning => $this->routeOwning($request),
            ServingNode::TaskGroupOwning => $this->taskGroupOwning($request),
            ServingNode::RoleMutation => $this->roleMutation($request),
            ServingNode::Collection => [],
            ServingNode::Caller => $this->caller($request),
        };
    }

    /**
     * ADR 0124: a Task group with an Instance is served by that Instance's Node, so a Node with access to itself
     * can manage the groups whose workspace it holds. A group without an Instance is served by the Gateway.
     *
     * @return list<Node>
     */
    private function taskGroupOwning(Request $request): array
    {
        $group = $request->route('group');

        if (! $group instanceof TaskGroup) {
            $id = $this->positiveInteger($group);
            $group = $id === null ? null : TaskGroup::query()->find($id);
        }

        $instance = $group?->taskable;

        return $instance instanceof AppInstance ? [Node::query()->findOrFail($instance->node_id)] : $this->gateway();
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
                $query->whereIn('id', $app->appInstances()->select('node_id'));
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

        if ($instance instanceof AppInstance) {
            return [Node::query()->findOrFail($instance->node_id)];
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId === null) {
            return [];
        }

        return [Node::query()->findOrFail($nodeId)];
    }

    /** @return list<Node> */
    private function deploymentOwning(Request $request): array
    {
        $deployment = $request->route('deployment');

        if (! $deployment instanceof AppInstanceDeployment) {
            return [];
        }

        return [Node::query()->findOrFail($deployment->appInstance->node_id)];
    }

    /** @return list<Node> */
    private function candidateClone(Request $request): array
    {
        $candidate = $request->route('candidate');

        if (! $candidate instanceof AppInstance) {
            return [];
        }

        $candidateNode = Node::query()->findOrFail($candidate->node_id);
        $destinationNodeId = $this->positiveInteger($request->input('node_id'));

        if ($destinationNodeId === null) {
            return [$candidateNode];
        }

        $destinationNode = Node::query()->findOrFail($destinationNodeId);

        if ($candidateNode->is($destinationNode)) {
            return [$candidateNode];
        }

        return [$candidateNode, $destinationNode];
    }

    /** @return list<Node> */
    private function instanceTransfer(Request $request): array
    {
        $instance = $request->route('instance');

        if (! $instance instanceof AppInstance) {
            return [];
        }

        $sourceNode = Node::query()->findOrFail($instance->node_id);
        $destinationNodeId = $this->positiveInteger($request->input('node_id'));

        if ($destinationNodeId === null) {
            return [$sourceNode];
        }

        $destinationNode = Node::query()->findOrFail($destinationNodeId);

        if ($sourceNode->is($destinationNode)) {
            return [$sourceNode];
        }

        return [$sourceNode, $destinationNode];
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
                ->whereHas('routes', static fn ($query) => $query->where('domain', $target))
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

    /**
     * @return list<Node>
     */
    private function herdrSessionOwning(Request $request): array
    {
        $session = $request->route('session');

        if ($session instanceof HerdrSession) {
            return [Node::query()->findOrFail($session->node_id)];
        }

        $nodeId = $this->positiveInteger($request->input('node_id'));

        if ($nodeId === null) {
            return [];
        }

        return [Node::query()->findOrFail($nodeId)];
    }

    /**
     * @return list<Node>
     */
    private function processOwning(Request $request): array
    {
        $process = $request->route('process');

        if ($process instanceof Process) {
            return match (true) {
                AppInstance::isMorphType($process->owner_type) => [Node::query()->findOrFail(
                    AppInstance::query()->findOrFail($process->owner_id)->node_id,
                )],
                $process->owner_type === Node::class => [Node::query()->findOrFail($process->owner_id)],
                default => throw new ResourceOperationException(
                    errorCode: 'process.target_unsupported',
                    message: 'The Process owner is not a supported AppInstance or Node.',
                    status: 409,
                ),
            };
        }

        $targetType = $request->input('target_type');
        $targetId = $this->positiveInteger($request->input('target_id'));
        $selector = $request->input('target_id');
        if ($targetType === 'instance' && $targetId === null && is_string($selector) && preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z0-9-]+\z/D', $selector) === 1) {
            $instances = AppInstance::query()->where('environment', 'development')->whereHas('routes', static fn ($query) => $query->where('domain', $selector))->limit(2)->get();
            if ($instances->count() > 1) {
                throw new ResourceOperationException('process.target_ambiguous', 'The Route matches multiple development AppInstances.', 409);
            }
            if ($instances->isEmpty()) {
                throw new ModelNotFoundException()->setModel(AppInstance::class);
            }
            $targetId = $instances->sole()->id;
            $request->merge(['target_id' => $targetId]);
        }

        if (! is_string($targetType) || $targetId === null) {
            return [];
        }

        return match ($targetType) {
            'instance' => [Node::query()->findOrFail(
                AppInstance::query()->findOrFail($targetId)->node_id,
            )],
            'node' => [Node::query()->findOrFail($targetId)],
            default => [],
        };
    }

    /** @return list<Node> */
    private function appInstanceHost(Request $request): array
    {
        $instance = $request->route('instance');

        if ($instance instanceof AppInstance) {
            return [Node::query()->findOrFail($instance->node_id)];
        }

        $instanceId = $this->positiveInteger($instance);

        if ($instanceId === null) {
            return [];
        }

        $instance = AppInstance::query()->find($instanceId);

        return $instance instanceof AppInstance
            ? [Node::query()->findOrFail($instance->node_id)]
            : [];
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
            return match (true) {
                $schedule->target_type === Node::class => [Node::query()->findOrFail($schedule->target_id)],
                AppInstance::isMorphType($schedule->target_type) => [Node::query()->findOrFail(
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
