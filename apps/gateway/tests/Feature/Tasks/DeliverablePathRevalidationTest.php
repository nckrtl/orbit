<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\DeliverablePathChecker;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\NullTaskWorkspaceStateReader;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;

/** @param list<array<string, mixed>> $deliverables */
function revalidation_fixture(array $deliverables, string $base = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', bool $provisional = false): array
{
    $project = Project::query()->create([
        'name' => 'Path revalidation', 'slug' => 'path-revalidation',
        'repository_url' => 'git@example.test:revalidation.git', 'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Revalidate before start', 'brief' => 'Check the actual base.',
        'status' => TaskGroupStatus::Running, 'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $repository = new class implements DeliverablePathRepository
    {
        public int $defaultLookups = 0;

        /** @var list<string> */
        public array $commits = [];

        /** @var array<string, list<string>> */
        public array $trees = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['app/Present.php']];

        public bool $unavailable = false;

        public function defaultBranchCommit(Project $project): string
        {
            $this->defaultLookups++;

            return str_repeat('a', 40);
        }

        public function files(Project $project, string $commit): array
        {
            $this->commits[] = $commit;
            if ($this->unavailable) {
                throw new ResourceOperationException(errorCode: 'tasks.deliverable_base_unavailable', message: 'Base tree unavailable.');
            }

            return $this->trees[$commit] ?? [];
        }
    };
    app()->instance(DeliverablePathRepository::class, $repository);
    if ($provisional) {
        expect($group->taskable)->toBeNull();
        expect(app(DeliverablePathChecker::class)->check($project, $deliverables, $repository->defaultBranchCommit($project), 'provisional'))->toBe([]);
        $repository->commits = [];
        $repository->defaultLookups = 0;
    }
    $node = Node::query()->create([
        'name' => 'revalidation-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.129', 'wireguard_ip' => '10.44.0.129',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'revalidation',
        'checkout_path' => '/tmp/task-revalidation', 'status' => 'reserved', 'starting_commit' => $base,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Implement', 'brief' => 'Deliver the paths.',
        'status' => TaskStatus::Todo, 'deliverables' => $deliverables,
    ]);
    $spawner = new class implements AgentSpawner
    {
        public int $implementers = 0;

        public bool $fail = false;

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers++;
            if ($this->fail) {
                return null;
            }

            return test_agent_thread($task->parent, 'revalidation-implementer-'.$task->id, $task)->id;
        }

        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task, 'revalidation-reviewer')->id;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(TaskWorkspaceStateReader::class, new NullTaskWorkspaceStateReader);
    app(TaskExtensionState::class)->enable();

    return [$group, $task, $repository, $spawner];
}

it('blocks a provisional plan on a release seed missing its paths before any agent starts', function (): void {
    [$group, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php', 'change' => 'modified'],
    ], provisional: true);

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($spawner->implementers)->toBe(0);
    expect($repository->commits)->toBe([str_repeat('b', 40)]);
    expect($repository->defaultLookups)->toBe(0);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toContain('implementation', 'app/Present.php', str_repeat('b', 40));
    expect($task->fresh()?->implementer_agent_thread_id)->toBeNull();
    expect($group->fresh()?->assistance_requested)->toBeTrue();
});

it('revalidates a successor against its previous approved sibling commit', function (): void {
    [$group, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $task->update(['position' => 2]);
    $previous = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Approved sibling', 'brief' => 'Done.', 'status' => TaskStatus::Completed,
    ]);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $previous->id, 'type' => TaskCommentType::Approved, 'author' => 'reviewer', 'posted_at' => now(), 'body' => 'Approved.', 'commit_sha' => str_repeat('c', 40),
    ]);
    $repository->trees[str_repeat('b', 40)] = ['app/Present.php'];

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($spawner->implementers)->toBe(0);
    expect($repository->commits)->toBe([str_repeat('c', 40)]);
    expect($repository->defaultLookups)->toBe(0);
    expect($task->fresh()?->assistance_reason)->toContain('implementation', 'app/Present.php', str_repeat('c', 40));
});

it('starts normally using the recorded resolved base without looking up the default branch', function (): void {
    [, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $task->update(['subtask_start_commit' => str_repeat('d', 40)]);
    $repository->trees[str_repeat('d', 40)] = ['app/Present.php'];

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($spawner->implementers)->toBe(1);
    expect($repository->commits)->toBe([str_repeat('d', 40)]);
    expect($repository->defaultLookups)->toBe(0);
    expect($task->fresh()?->assistance_requested)->toBeFalse();
    expect($task->fresh()?->implementer_agent_thread_id)->not->toBeNull();
});

it('checks the workspace head recorded at start rather than the release seed', function (): void {
    [, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    app()->instance(TaskWorkspaceStateReader::class, new class implements TaskWorkspaceStateReader
    {
        public function headCommit(Instance $instance): ?string
        {
            return str_repeat('e', 40);
        }

        public function currentBranch(Instance $instance): ?string
        {
            return 'task-revalidation';
        }
    });
    $repository->trees[str_repeat('e', 40)] = ['app/Present.php'];

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($task->fresh()?->subtask_start_commit)->toBe(str_repeat('e', 40));
    expect($repository->commits)->toBe([str_repeat('e', 40)]);
    expect($repository->defaultLookups)->toBe(0);
    expect($spawner->implementers)->toBe(1);
});

it('blocks the next implementer when a running sibling is cancelled', function (): void {
    [$group, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $task->update(['position' => 2]);
    $previous = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Cancel this sibling', 'brief' => 'Stopped.', 'status' => TaskStatus::Running,
    ]);
    $previous->update(['implementer_agent_thread_id' => test_agent_thread($group, 'previous-implementer', $previous)->id]);

    app(TaskScheduler::class)->cancelRunningSubtask($group, $previous, static function (): void {});

    expect($previous->fresh()?->status)->toBe(TaskStatus::Cancelled);
    expect($task->fresh()?->status)->toBe(TaskStatus::Running);
    expect($repository->commits)->toBe([str_repeat('b', 40)]);
    expect($spawner->implementers)->toBe(0);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toContain('implementation', 'app/Present.php', str_repeat('b', 40));
});

it('accepts companion created markers for command paths at the resolved base', function (): void {
    [, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'new-test', 'type' => 'file', 'path' => 'tests/**/NewTest.php', 'change' => 'created'],
        ['id' => 'regression', 'type' => 'command', 'command' => 'vendor/bin/pest tests/Feature/NewTest.php', 'paths' => ['tests/Feature/NewTest.php'], 'fails_on_base' => true],
    ]);

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($spawner->implementers)->toBe(1);
    expect($repository->commits)->toBe([str_repeat('b', 40)]);
    expect($task->fresh()?->assistance_requested)->toBeFalse();
});

it('reports every missing file glob and invalid non-test base overlay without spawning', function (): void {
    [, $task, , $spawner] = revalidation_fixture([
        ['id' => 'missing-glob', 'type' => 'file', 'path' => 'src/**/*.php'],
        ['id' => 'new-implementation', 'type' => 'file', 'path' => 'app/New.php', 'change' => 'created'],
        ['id' => 'bad-overlay', 'type' => 'command', 'command' => 'php app/New.php', 'paths' => ['app/New.php'], 'fails_on_base' => true],
    ]);

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($spawner->implementers)->toBe(0);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toContain('missing-glob', 'src/**/*.php', 'bad-overlay', 'app/New.php', str_repeat('b', 40));
});

it('requests assistance instead of starting with an unresolved review base even without path deliverables', function (): void {
    [, $task, $repository, $spawner] = revalidation_fixture([], base: '');

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect(TaskReviewBase::commit($task->fresh()))->toBe('');
    expect($spawner->implementers)->toBe(0);
    expect($repository->commits)->toBe([]);
    expect($repository->defaultLookups)->toBe(0);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toContain('base', 'unresolved');
});

it('requests assistance when the resolved base tree cannot be read', function (): void {
    [, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $repository->unavailable = true;

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect($spawner->implementers)->toBe(0);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toContain(str_repeat('b', 40), 'Base tree unavailable');
});

it('rechecks deliverable paths on an implementer spawn retry', function (): void {
    [$group, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $repository->trees[str_repeat('b', 40)] = ['app/Present.php'];
    $spawner->fail = true;
    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();
    expect($spawner->implementers)->toBe(1);
    expect($task->fresh()?->status)->toBe(TaskStatus::Failed);
    $repository->trees[str_repeat('b', 40)] = [];
    $spawner->fail = false;
    $group->refresh()->update(['status' => TaskGroupStatus::Running]);
    $task->refresh()->update(['status' => TaskStatus::Running]);

    app(TaskScheduler::class)->tick();

    expect($spawner->implementers)->toBe(1);
    expect($repository->commits)->toBe([str_repeat('b', 40), str_repeat('b', 40)]);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toContain('implementation', 'app/Present.php', str_repeat('b', 40));
});
