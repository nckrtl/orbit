<?php

declare(strict_types=1);

namespace App\Http\Authorization;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Tool;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * @mago-expect lint:cyclomatic-complexity Each serving scope has one explicit resolution path.
 * @mago-expect lint:kan-defect The cohesive resolver keeps route vocabulary and 404/422 boundaries together.
 * @mago-expect lint:too-many-methods Each private method resolves one closed serving-node scope.
 */
final readonly class ServingNodeResolver
{
    /** @return list<Node> */
    public function resolve(Request $request, ServingNode $scope): array
    {
        return match ($scope) {
            ServingNode::Gateway => $this->gateway(),
            ServingNode::Caller => $this->caller($request),
            ServingNode::Target => $this->target($request),
            ServingNode::AppOwning => $this->appOwning($request),
            ServingNode::InstanceOwning => $this->instanceOwning($request),
            ServingNode::CallerInstanceOwning => $this->instanceOwning($request),
            ServingNode::WorkspaceOwning => $this->workspaceOwning($request),
            ServingNode::ProcessOwning => $this->processOwning($request),
            ServingNode::ToolOwning => $this->toolOwning($request),
            ServingNode::ClusterOwning => $this->clusterOwning($request),
            ServingNode::RoleMutation => $this->roleMutation($request),
            ServingNode::Collection => [],
        };
    }

    /** @return list<Node> */
    private function caller(Request $request): array
    {
        /** @mago-expect analysis:mixed-assignment The authenticated peer resolver returns a Node. */
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
     * @mago-expect analysis:mixed-assignment Request input is an untyped boundary.
     * @return list<Node>
     */
    private function processOwning(Request $request): array
    {
        $process = $request->route('process');

        if ($process instanceof Process) {
            return [$this->ownerNode($process->owner)];
        }

        $targetType = $request->input('target_type');
        $targetId = $this->positiveInteger($request->input('target_id'));

        if (! is_string($targetType) || $targetId === null) {
            return [];
        }

        $owner = match ($targetType) {
            'instance' => Instance::query()->findOrFail($targetId),
            'workspace' => Workspace::query()->findOrFail($targetId),
            default => null,
        };

        if (! $owner instanceof Instance && ! $owner instanceof Workspace) {
            return [];
        }

        return [$this->ownerNode($owner)];
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

    /**
     * @return list<Node>
     * @mago-expect analysis:mixed-assignment Route and request input are untyped transport boundaries.
     */
    private function roleMutation(Request $request): array
    {
        $role = $request->route('role') ?? $request->input('role');

        if ($role === RoleName::Metrics->value || $role === RoleName::Metrics) {
            return $this->gateway();
        }

        return $this->target($request);
    }

    private function ownerNode(Instance|Workspace $owner): Node
    {
        if ($owner instanceof Instance) {
            return Node::query()->findOrFail($owner->node_id);
        }

        $instance = Instance::query()->findOrFail($owner->instance_id);

        return Node::query()->findOrFail($instance->node_id);
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
