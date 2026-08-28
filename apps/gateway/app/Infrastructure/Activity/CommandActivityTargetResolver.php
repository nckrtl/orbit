<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Processes\ProcessTargetType;
use App\Domain\Tools\ToolOperationException;
use App\Models\App as OrbitApp;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Tool;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * @mago-expect lint:cyclomatic-complexity Route vocabulary requires explicit model resolution branches.
 * @mago-expect lint:kan-defect The branches fail closed when route input cannot identify one subject.
 */
final readonly class CommandActivityTargetResolver
{
    /** @return array{subject_type?: string, subject_id?: int, target_node_id: ?int}|null */
    public function resolve(Request $request, ?ToolOperationException $exception = null): ?array
    {
        if (str_starts_with((string) $request->route()?->getName(), 'tool:')) {
            return $this->resolveTool($request, $exception);
        }

        $subject = $this->subject($request);

        if (! $subject instanceof Model) {
            return null;
        }

        return [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (int) $subject->getKey(),
            'target_node_id' => $this->targetNodeId($subject),
        ];
    }

    /** @return array{subject_type?: string, subject_id?: int, target_node_id: ?int} */
    private function resolveTool(Request $request, ?ToolOperationException $exception): array
    {
        $tool = $this->tool($request, $exception);
        $nodeId = $this->toolNodeId($request, $tool, $exception);
        $result = ['target_node_id' => $nodeId];

        if ($tool instanceof Tool) {
            $result['subject_type'] = $tool->getMorphClass();
            $result['subject_id'] = (int) $tool->getKey();
        }

        return $result;
    }

    /** @mago-expect analysis:mixed-assignment Request attributes are an untyped boundary. */
    private function tool(Request $request, ?ToolOperationException $exception): ?Tool
    {
        $snapshot = $request->attributes->get('orbit.tool_snapshot');

        if ($snapshot instanceof Tool) {
            return $snapshot;
        }

        $bound = $request->route('tool');

        if ($bound instanceof Tool) {
            return $bound;
        }

        $identity = $this->toolIdentity($request, $exception);

        if ($identity === null || $request->route()?->getName() !== 'tool:install') {
            return null;
        }

        return Tool::query()
            ->with('manager')
            ->where('node_id', $identity['node_id'])
            ->where('package', $identity['package'])
            ->whereHas('manager', static function (Builder $query) use ($identity): void {
                $query
                    ->where('node_id', $identity['node_id'])
                    ->where('name', $identity['manager']);
            })
            ->first();
    }

    /**
     * @return array{node_id: int, manager: string, package: string}|null
     * @mago-expect analysis:mixed-assignment Request attributes are an untyped boundary.
     */
    private function toolIdentity(Request $request, ?ToolOperationException $exception): ?array
    {
        if ($exception instanceof ToolOperationException) {
            return [
                'node_id' => $exception->nodeId,
                'manager' => $exception->manager,
                'package' => $exception->package,
            ];
        }

        $activity = $request->attributes->get('orbit.tool_activity');

        if (
            ! is_array($activity)
            || ! is_int($activity['node_id'] ?? null)
            || ! is_string($activity['manager'] ?? null)
            || ! is_string($activity['package'] ?? null)
        ) {
            return null;
        }

        return [
            'node_id' => $activity['node_id'],
            'manager' => $activity['manager'],
            'package' => $activity['package'],
        ];
    }

    /** @mago-expect analysis:mixed-assignment The database value is checked before return. */
    private function toolNodeId(
        Request $request,
        ?Tool $tool,
        ?ToolOperationException $exception,
    ): ?int {
        if ($tool instanceof Tool) {
            return $tool->node_id;
        }

        $identity = $this->toolIdentity($request, null);
        $nodeId = match (true) {
            $exception instanceof ToolOperationException => $exception->nodeId,
            $identity !== null => $identity['node_id'],
            default => $request->integer('node_id'),
        };

        $targetNodeId = Node::query()->whereKey($nodeId)->value('id');

        return is_int($targetNodeId) ? $targetNodeId : null;
    }

    private function subject(Request $request): ?Model
    {
        if (in_array($request->route()?->getName(), ['firewall:allow', 'firewall:deny'], strict: true)) {
            return $this->createdFirewallRule($request);
        }

        foreach (['firewallRule', 'process', 'workspace', 'instance', 'app', 'servingNode', 'node'] as $parameter) {
            $model = $request->route($parameter);

            if ($model instanceof Model) {
                return $model;
            }
        }

        return match ($request->route()?->getName()) {
            'node:provision' => Node::query()->where('name', $request->input('name'))->first(),
            'app:new' => OrbitApp::query()->where('slug', $request->input('slug'))->first(),
            'instance:new' => Instance::query()
                ->where('app_id', $request->integer('app_id'))
                ->where('node_id', $request->integer('node_id'))
                ->first(),
            'workspace:new' => Workspace::query()
                ->where('instance_id', $request->integer('instance_id'))
                ->where('name', $request->input('name'))
                ->first(),
            'process:add' => $this->createdProcess($request),
            'process:list' => $this->processOwner($request),
            'firewall:allow', 'firewall:deny' => $this->createdFirewallRule($request),
            default => null,
        };
    }

    /** @mago-expect analysis:mixed-assignment Request input is an untyped boundary. */
    private function createdFirewallRule(Request $request): ?FirewallRule
    {
        $node = $request->route('node');
        $name = $request->input('name');

        if (! $node instanceof Node || ! is_string($name)) {
            return null;
        }

        return FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('name', $name)
            ->first();
    }

    /** @mago-expect analysis:mixed-assignment Request input is an untyped boundary. */
    private function createdProcess(Request $request): ?OrbitProcess
    {
        $targetType = $request->input('target_type');
        $name = $request->input('name');

        if (! is_string($targetType) || ! is_string($name)) {
            return null;
        }

        $type = ProcessTargetType::tryFrom($targetType);

        if ($type === null) {
            return null;
        }

        return OrbitProcess::query()
            ->where('owner_type', $type->modelClass())
            ->where('owner_id', $request->integer('target_id'))
            ->where('name', $name)
            ->first();
    }

    private function processOwner(Request $request): ?Model
    {
        $targetType = $request->query('target_type');

        if (! is_string($targetType)) {
            return null;
        }

        return match (ProcessTargetType::tryFrom($targetType)) {
            ProcessTargetType::Instance => Instance::query()->find($request->integer('target_id')),
            ProcessTargetType::Workspace => Workspace::query()->find($request->integer('target_id')),
            null => null,
        };
    }

    private function targetNodeId(Model $subject): ?int
    {
        if ($subject instanceof Node) {
            return $subject->exists ? $subject->id : null;
        }

        if ($subject instanceof Instance) {
            return $subject->node_id;
        }

        if ($subject instanceof Tool) {
            return $subject->node_id;
        }

        if ($subject instanceof FirewallRule) {
            return $subject->node_id;
        }

        if ($subject instanceof OrbitProcess) {
            $owner = $subject->owner()->first();

            return $owner instanceof Model ? $this->targetNodeId($owner) : null;
        }

        if (! $subject instanceof Workspace) {
            return null;
        }

        return $subject->instance()->first()?->node_id;
    }
}
