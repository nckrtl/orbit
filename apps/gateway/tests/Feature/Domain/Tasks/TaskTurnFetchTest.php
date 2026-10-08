<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\FakeAgentDriver;

/**
 * @return array{0: Project, 1: Node, 2: Instance, 3: Task}
 */
function turn_fetch_group(string $slug, string $status = TaskGroupStatus::Todo->value): array
{
    $project = Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => $slug.'-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $slug,
        'checkout_path' => '/tmp/tasks-'.$slug,
        'starting_commit' => str_repeat('a', 40),
        'status' => 'reserved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Fetch before the turn',
        'brief' => 'Fetch origin first.',
        'status' => $status,
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return [$project, $node, $instance, $group->fresh(['tasks', 'project', 'taskable']) ?? $group];
}

/** @return TaskBaseBranchFetcher&object{order: list<string>} */
function turn_fetch_fetcher(bool $fail = false): TaskBaseBranchFetcher
{
    return new class($fail) implements TaskBaseBranchFetcher
    {
        /** @var list<string> */
        public array $order = [];

        public function __construct(private bool $fail) {}

        public function fetch(Task $group, string $base): void {}

        public function fastForward(Task $group, bool $missingRefOk = false): void {}

        public function resetToDefault(Task $group): string
        {
            return str_repeat('c', 40);
        }

        public function mergeBase(Task $group): string
        {
            return str_repeat('c', 40);
        }

        public function moveTo(Task $group, string $sha): void {}

        public function fetchForTurn(Task $group): void
        {
            $this->order[] = 'fetch';
            if ($this->fail) {
                throw new TaskPullRequestException('The workspace refs could not be fetched.');
            }
        }
    };
}

function turn_fetch_driver(object $fetcher): FakeAgentDriver
{
    $driver = new FakeAgentDriver('pi');
    $driver->beforeTurn = function () use ($fetcher): void {
        $fetcher->order[] = 'turn';
    };
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app()->instance(AgentSpawner::class, app(TaskAgentSpawner::class));
    app()->instance(TaskBaseBranchFetcher::class, $fetcher);

    return $driver;
}

it('tells the agent Orbit fetches before each turn and not to fetch or push even when the opening fetch fails', function (): void {
    [, , $instance, $group] = turn_fetch_group('turn-fetch-open');
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Opening',
        'brief' => 'Start the implementer.',
        'status' => TaskStatus::Todo,
    ]);
    $fetcher = turn_fetch_fetcher(fail: true);
    $driver = turn_fetch_driver($fetcher);
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    $task = $group->tasks()->first();
    expect($fetcher->order)->toBe(['fetch', 'turn'])
        ->and($driver->calls[0]['operation'] ?? null)->toBe('create')
        ->and($driver->calls[0]['prompt'] ?? '')->toStartWith("The fetch of origin failed. origin/* may be stale.\n\n")
        ->and($driver->calls[0]['prompt'] ?? '')->toContain('Orbit fetches origin before every agent turn. Do not fetch or push. Orbit publishes the approved commit itself.')
        ->and($driver->calls[0]['prompt'] ?? '')->toContain('Start the implementer.')
        ->and($task?->status)->toBe(TaskStatus::Running)
        ->and($task?->fresh()?->status)->not->toBe(TaskStatus::Failed)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task?->implementer_agent_thread_id)->not->toBeNull();
});

it('tells the agent not to fetch or push in opening and continued reviewer turns', function (bool $continued): void {
    [, $node, , $group] = turn_fetch_group('turn-fetch-review', TaskGroupStatus::Running->value);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Continued review',
        'brief' => 'Review the scheduler.',
        'status' => TaskStatus::Running,
        'subtask_start_commit' => str_repeat('a', 40),
    ]);
    if ($continued) {
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id,
            'task_id' => $task->id,
            'node_id' => $node->id,
            'driver' => 'pi',
            'runtime_key' => 'node:'.$node->id,
            'external_id' => 'reviewer-thread',
            'role' => 'reviewer',
            'model' => 'claude-opus-5',
            'effort' => 'high',
        ]);
        $group->update(['reviewer_agent_thread_id' => $thread->id]);
    }
    $fetcher = turn_fetch_fetcher();
    $driver = turn_fetch_driver($fetcher);

    app(TaskScheduler::class)->settleImplementer($task->fresh() ?? $task);

    $message = $driver->calls[0][$continued ? 'message' : 'prompt'] ?? '';
    expect($fetcher->order)->toBe(['fetch', 'turn'])
        ->and($driver->calls[0]['operation'] ?? null)->toBe($continued ? 'send' : 'create')
        ->and($message)->toContain('Review subtask #'.$task->id.': Continued review')
        ->and($message)->toContain('Orbit fetches origin before every agent turn. Do not fetch or push. Orbit publishes the approved commit itself.')
        ->and($message)->not->toContain('The fetch of origin failed. origin/* may be stale.')
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing);
})->with(['opening' => false, 'continued' => true]);

it('fetches before an operator resolution is sent and still delivers it if the fetch fails', function (TaskStatus $status, bool $fail): void {
    [, $node, , $group] = turn_fetch_group('turn-fetch-resolution', TaskGroupStatus::Running->value);
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'Access is missing.']);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Blocked',
        'brief' => 'Continue after access is restored.',
        'status' => $status,
        'assistance_requested' => true,
        'assistance_reason' => 'Access is missing.',
    ]);
    $role = $status === TaskStatus::Reviewing ? 'reviewer' : 'implementer';
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $task->id,
        'node_id' => $node->id,
        'driver' => 'pi',
        'runtime_key' => 'node:'.$node->id,
        'external_id' => $role.'-thread',
        'role' => $role,
        'model' => 'claude-opus-5',
        'effort' => 'high',
    ]);
    if ($status === TaskStatus::Reviewing) {
        $group->update(['reviewer_agent_thread_id' => $thread->id]);
    } else {
        $task->update(['implementer_agent_thread_id' => $thread->id]);
    }
    $fetcher = turn_fetch_fetcher(fail: $fail);
    $driver = turn_fetch_driver($fetcher);

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Access has been restored.', 'author' => 'operator',
    ]);

    $prefix = $fail ? "The fetch of origin failed. origin/* may be stale.\n\n" : '';
    expect($fetcher->order)->toBe(['fetch', 'turn'])
        ->and($driver->calls[0]['thread'] ?? null)->toBe($thread->external_id)
        ->and($driver->calls[0]['message'] ?? null)->toBe($prefix.'Access has been restored.')
        ->and($task->fresh()?->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($comment->body)->toBe('Access has been restored.');
})->with([
    'implementer success' => [TaskStatus::Running, false],
    'implementer failure' => [TaskStatus::Running, true],
    'reviewer success' => [TaskStatus::Reviewing, false],
    'reviewer failure' => [TaskStatus::Reviewing, true],
]);

it('does not fetch for a resolution that does not start a turn', function (): void {
    [, , , $group] = turn_fetch_group('turn-fetch-unused-resolution', TaskGroupStatus::Running->value);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Not blocked',
        'brief' => 'Continue working.', 'status' => TaskStatus::Running,
    ]);
    $fetcher = turn_fetch_fetcher();
    $driver = turn_fetch_driver($fetcher);

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Access has been restored.', 'author' => 'operator',
    ]);

    expect($fetcher->order)->toBe([])
        ->and($driver->calls)->toBe([])
        ->and($comment->body)->toBe('Access has been restored.');
});

it('fetches before a restarted turn and still sends it when the fetch fails', function (): void {
    [, $node, , $group] = turn_fetch_group('turn-fetch-restart', TaskGroupStatus::Running->value);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Restarted',
        'brief' => 'Continue after the restart.',
        'status' => TaskStatus::Running,
        'started_at' => now(),
    ]);
    $thread = test_agent_thread($group, 'implementer-thread', $task);
    $thread->update(['driver' => 'pi']);
    $task->update(['implementer_agent_thread_id' => $thread->id]);
    $node->update([
        'settings' => ['pi' => ['token' => 'pi-node-token-with-more-than-32-characters', 'url' => 'http://10.44.0.81:3774']],
    ]);
    $fetcher = turn_fetch_fetcher(fail: true);
    app()->instance(TaskBaseBranchFetcher::class, $fetcher);
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    Http::fake(function (Request $request) use ($fetcher) {
        if (str_ends_with($request->url(), '/messages')) {
            $fetcher->order[] = 'turn';

            return Http::response(['duplicate' => false], 202);
        }
        if (str_contains($request->url(), '/sessions/implementer-thread')) {
            return Http::response([
                'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
                'session' => ['id' => 'implementer-thread'],
                'state' => 'failed', 'error' => TaskScheduler::PiServerRestartError, 'turnId' => 'turn-key-1', 'entries' => [],
            ]);
        }

        return Http::response([], 404);
    });
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    $sent = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
    expect($fetcher->order)->toBe(['fetch', 'turn'])
        ->and($sent)->toBeInstanceOf(Request::class)
        ->and($sent['text'] ?? '')->toContain('The fetch of origin failed. origin/* may be stale.')
        ->and($sent['text'] ?? '')->toContain(TaskScheduler::PiServerRestartContinue)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});
