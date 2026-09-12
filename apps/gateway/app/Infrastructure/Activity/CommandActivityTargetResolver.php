<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Processes\ProcessTargetType;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Tools\ToolOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Tool;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
        $registration = $request->attributes->get('orbit.app_instance_registration');

        if ($registration instanceof AppInstance) {
            return $registration;
        }

        if (str_starts_with((string) $request->route()?->getName(), 'process:')) {
            return $this->processOwner($request);
        }

        if (str_starts_with((string) $request->route()?->getName(), 'schedule:')) {
            return $this->scheduleTarget($request);
        }

        if (in_array($request->route()?->getName(), ['firewall:allow', 'firewall:deny'], strict: true)) {
            return $this->createdFirewallRule($request);
        }

        foreach ([
            'firewallRule',
            'process',
            'workspace',
            'instance',
            'route',
            'app',
            'servingNode',
            'node',
        ] as $parameter) {
            $model = $request->route($parameter);

            if ($model instanceof Model) {
                return $model;
            }
        }

        return match ($request->route()?->getName()) {
            'node:provision' => Node::query()->where('name', $request->input('name'))->first(),
            'app:new' => OrbitApp::query()->where('slug', $request->input('slug'))->first(),
            'instance:new' => AppInstance::query()
                ->where('app_id', $request->integer('app_id'))
                ->where('name', $request->input('name'))
                ->first(),
            'route:new' => Route::query()
                ->where('hostname', mb_strtolower(trim((string) $request->input('hostname'))))
                ->first(),
            'workspace:new' => Workspace::query()
                ->where('instance_id', $request->integer('instance_id'))
                ->where('name', $request->input('name'))
                ->first(),
            'firewall:allow', 'firewall:deny' => $this->createdFirewallRule($request),
            default => null,
        };
    }

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

    private function processOwner(Request $request): ?AppInstance
    {
        $process = $request->route('process');

        if (! $process instanceof OrbitProcess && $request->route()?->getName() === 'process:add') {
            $process = $this->createdProcess($request);
        }

        if ($process instanceof OrbitProcess) {
            if ($process->owner_type !== AppInstance::class) {
                return null;
            }

            return AppInstance::query()->find($process->owner_id);
        }

        $targetType = $request->query('target_type');

        if ($request->isMethod('POST')) {
            $targetType = $request->input('target_type');
        }

        if ($targetType !== ProcessTargetType::AppInstance->value) {
            return null;
        }

        return AppInstance::query()->find($request->integer('target_id'));
    }

    private function scheduleTarget(Request $request): Node|AppInstance|null
    {
        $schedule = $request->attributes->get('orbit.schedule_activity');

        if (! $schedule instanceof Schedule) {
            $schedule = $request->route('schedule');
        }

        if ($schedule instanceof Schedule) {
            return match ($schedule->target_type) {
                Node::class => Node::query()->find($schedule->target_id),
                AppInstance::class => AppInstance::query()->find($schedule->target_id),
                default => null,
            };
        }

        if ($request->route()?->getName() !== 'schedule:add') {
            return null;
        }

        $type = $request->input('target_type');
        $targetId = $request->input('target_id');

        if (! is_string($type) || ! is_int($targetId) || $targetId < 1) {
            return null;
        }

        return match (ScheduleTargetType::tryFrom($type)) {
            ScheduleTargetType::Node => Node::query()->find($targetId),
            ScheduleTargetType::AppInstance => AppInstance::query()->find($targetId),
            default => null,
        };
    }

    private function targetNodeId(Model $subject): ?int
    {
        if ($subject instanceof Node) {
            return $subject->exists ? $subject->id : null;
        }

        if ($subject instanceof AppInstance || $subject instanceof Instance) {
            return $subject->node_id;
        }

        if ($subject instanceof Tool) {
            return $subject->node_id;
        }

        if ($subject instanceof Route) {
            if ($subject->node_id !== null) {
                return $subject->node_id;
            }

            $targetNodeId = $subject->targets()->with('appInstance')->first()?->appInstance?->node_id;

            if ($targetNodeId !== null) {
                return $targetNodeId;
            }

            return $subject->cluster?->routerAssignment?->node_id;
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
