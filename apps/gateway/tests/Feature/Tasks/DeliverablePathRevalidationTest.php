<?php

declare(strict_types=1);

use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\DeliverablePathChecker;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\NullTaskWorkspaceStateReader;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\DeliverablePathWorkspace;
use Tests\Support\TestOrbitHome;

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

        public function files(Project $project, string $commit, ?Instance $workspace = null): array
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

it('allows a concurrent SQLite writer during a blocked remote correction fetch and rejects stale validation', function (string $change): void {
    $original = config('database.default');
    $database = tempnam(sys_get_temp_dir(), 'orbit-correction-db-');
    $schema = DB::select("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
    $writer = new PDO('sqlite:'.$database);
    foreach ($schema as $entry) {
        $writer->exec($entry->sql);
    }
    $writer->exec('CREATE TABLE writer_probe (value TEXT)');
    $writer->exec('PRAGMA busy_timeout=50');
    config(['database.connections.correction_probe' => [...config('database.connections.sqlite'), 'database' => $database], 'database.default' => 'correction_probe']);
    try {
        [$group, $task] = revalidation_fixture([['id' => 'paths', 'type' => 'file', 'path' => 'tests/MissingTest.php']]);
        $task->update(['status' => TaskStatus::Running, 'assistance_requested' => true, 'completion_attempt' => 2, 'subtask_start_commit' => str_repeat('b', 40)]);
        $receipt = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => TaskCommentType::ReadyForReview, 'author' => 'implementer', 'body' => 'Ready.', 'posted_at' => now(), 'completion_attempt' => 2]);
        $task->update(['completion_handoff_comment_id' => $receipt->id]);
        TaskCheck::query()->create(['task_id' => $task->id, 'task_comment_id' => $receipt->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Failed, 'failed_step' => 'invalid_deliverable', 'pid' => 123, 'process_started' => 'check-start', 'head_before' => str_repeat('b', 40), 'tree_before' => str_repeat('d', 40), 'started_at' => now(), 'finished_at' => now()]);
        $otherGroup = $group->replicate();
        $otherGroup->save();
        $runner = new class($writer, $task->id, $change, $otherGroup->id) implements ProcessRunner
        {
            public bool $blockedFetchObserved = false;

            public function __construct(private PDO $writer, private int $taskId, private string $change, private int $otherGroupId) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                if (in_array('fetch', $invocation->arguments, true)) {
                    $input = new InputStream;
                    $remote = new Process(['bash', '-c', 'printf fetch-blocked; read -r release'], input: $input, timeout: 30);
                    $remote->start();
                    try {
                        while (! str_contains($remote->getOutput(), 'fetch-blocked')) {
                            $remote->checkTimeout();
                            if (! $remote->isRunning()) {
                                throw new RuntimeException('Blocked fetch fixture exited before readiness.');
                            }
                            usleep(1000);
                        }
                        expect($remote->isRunning())->toBeTrue();
                        expect(DB::connection()->transactionLevel())->toBe(0);
                        $this->writer->exec("INSERT INTO writer_probe VALUES ('written while fetch blocked')");
                        if ($this->change === 'base') {
                            $this->writer->exec("UPDATE tasks SET subtask_start_commit = '".str_repeat('c', 40)."' WHERE id = ".$this->taskId);
                        } elseif ($this->change === 'consumed') {
                            $this->writer->exec('UPDATE tasks SET deliverable_correction_check_id = 999 WHERE id = '.$this->taskId);
                        } elseif ($this->change === 'ownership') {
                            $this->writer->exec('UPDATE tasks SET parent_id = '.$this->otherGroupId.' WHERE id = '.$this->taskId);
                        }
                        $this->blockedFetchObserved = true;
                        $input->write("release\n");
                        $input->close();
                        $remote->wait();
                    } finally {
                        $remote->stop();
                    }
                }
                $output = in_array('ls-tree', $invocation->arguments, true) ? '100644 blob '.str_repeat('b', 40)."\ttests/ExistingTest.php\0" : '';

                return new CommandResult(0, $output, '', 0, false);
            }
        };
        app()->instance(DeliverablePathRepository::class, new NativeDeliverablePathRepository($runner, app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::unreachableExecutor()));
        $replacement = [['id' => 'paths', 'type' => 'file', 'path' => 'tests/ExistingTest.php']];
        $update = fn () => app(UpdateTaskAction::class)->execute($group, $task, new UpdateTaskData(null, null, null, $replacement));

        if ($change === 'none') {
            $update();
            expect($task->fresh()?->deliverables)->toBe($replacement);
            expect(Activity::query()->where('description', 'deliverables corrected')->count())->toBe(1);
            expect($update)->toThrow(ResourceOperationException::class);
        } else {
            expect($update)->toThrow(ResourceOperationException::class);
            expect($task->fresh()?->deliverables)->toBe([['id' => 'paths', 'type' => 'file', 'path' => 'tests/MissingTest.php']]);
            expect(Activity::query()->where('description', 'deliverables corrected')->count())->toBe(0);
        }
        expect($runner->blockedFetchObserved)->toBeTrue();
        expect($writer->query('SELECT value FROM writer_probe')->fetchColumn())->toBe('written while fetch blocked');
    } finally {
        DB::purge('correction_probe');
        config(['database.default' => $original]);
        unlink($database);
    }
})->with(['none', 'base', 'consumed', 'ownership']);

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

/** Any origin read fails at once, so a passing gate proves the workspace answered. */
function revalidation_offline_origin(): ProcessRunner
{
    return new class implements ProcessRunner
    {
        public int $reads = 0;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->reads++;

            return new CommandResult(128, '', 'fatal: unable to access origin', 0, false);
        }
    };
}

it('starts a review-and-merge successor whose approved start commit exists only in the workspace', function (): void {
    [$group, $task, , $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/LocalOnly.php', 'change' => 'modified'],
    ]);
    $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    $git = DeliverablePathWorkspace::repositories();
    $group->taskable->update(['checkout_path' => $git['checkout']]);
    $task->update(['position' => 2]);
    $previous = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Approved sibling', 'brief' => 'Done.', 'status' => TaskStatus::Completed,
    ]);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $previous->id, 'type' => TaskCommentType::Approved, 'author' => 'reviewer', 'posted_at' => now(), 'body' => 'Approved.', 'commit_sha' => $git['local'],
    ]);
    $origin = revalidation_offline_origin();
    app()->instance(DeliverablePathRepository::class, new NativeDeliverablePathRepository($origin, app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::localExecutor()));
    expect($group->fresh(['project'])?->reviewsBeforePush())->toBeTrue();

    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();

    expect(TaskReviewBase::commit($task->fresh()))->toBe($git['local']);
    expect($task->fresh()?->assistance_requested)->toBeFalse();
    expect($spawner->implementers)->toBe(1);
    expect($origin->reads)->toBe(0);
});

it('resumes a subtask held by the path gate after the fault clears and an operator resolution', function (): void {
    [$group, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $repository->trees[str_repeat('b', 40)] = ['app/Present.php'];
    $repository->unavailable = true;
    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();
    expect($task->fresh()?->assistance_reason)->toStartWith('Deliverable path validation could not read base');
    expect($spawner->implementers)->toBe(0);

    app(TaskScheduler::class)->tick();
    expect($spawner->implementers)->toBe(0);

    $repository->unavailable = false;
    app(StoreTaskCommentAction::class)->execute($task->fresh(), ['type' => 'resolution', 'body' => 'The repository is readable again.', 'author' => 'operator']);

    expect($task->fresh()?->assistance_requested)->toBeFalse();
    expect($group->fresh()?->assistance_requested)->toBeFalse();
    expect(Activity::query()->where('subject_id', $task->id)->where('description', 'resolution queued deliverable gate retry')->count())->toBe(1);
    expect(Activity::query()->where('subject_id', $task->id)->where('description', 'resolution delivery failed')->count())->toBe(0);

    app(TaskScheduler::class)->tick();

    expect($spawner->implementers)->toBe(1);
    expect($task->fresh()?->assistance_requested)->toBeFalse();
    expect($task->fresh()?->implementer_agent_thread_id)->not->toBeNull();
});

it('accepts a deliverables fix for a subtask held by the path gate without using the one correction', function (): void {
    [$group, $task, $repository, $spawner] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Missing.php'],
    ]);
    $repository->trees[str_repeat('b', 40)] = ['app/Present.php'];
    app(TaskScheduler::class)->startTask($task);
    test_pass_baseline();
    expect($task->fresh()?->assistance_reason)->toContain('app/Missing.php');
    $update = fn (array $deliverables) => app(UpdateTaskAction::class)->execute($group->fresh(), $task->fresh(), new UpdateTaskData(null, null, null, $deliverables));

    expect(fn () => $update([['id' => 'implementation', 'type' => 'file', 'path' => 'app/StillMissing.php']]))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateTaskAction::class)->execute($group->fresh(), $task->fresh(), new UpdateTaskData('Renamed', null, null, [['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php']])))
        ->toThrow(ResourceOperationException::class);
    $fixed = [['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php']];
    $update($fixed);

    expect($task->fresh()?->deliverables)->toBe($fixed);
    expect($task->fresh()?->deliverable_correction_check_id)->toBeNull();
    $audit = Activity::query()->where('subject_id', $task->id)->where('description', 'deliverables corrected')->sole();
    expect($audit->properties?->get('gate'))->toBeTrue();
    expect($task->fresh()?->assistance_requested)->toBeTrue();

    app(StoreTaskCommentAction::class)->execute($task->fresh(), ['type' => 'resolution', 'body' => 'Deliverables fixed.', 'author' => 'operator']);
    app(TaskScheduler::class)->tick();

    expect($spawner->implementers)->toBe(1);
    expect($task->fresh()?->assistance_requested)->toBeFalse();
});

it('keeps an ordinary running subtask deliverables locked when the path gate did not stop it', function (): void {
    [$group, $task, $repository] = revalidation_fixture([
        ['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php'],
    ]);
    $repository->trees[str_repeat('b', 40)] = ['app/Present.php'];
    $task->update(['status' => TaskStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'The implementer is blocked: something else.']);

    expect(fn () => app(UpdateTaskAction::class)->execute($group->fresh(), $task->fresh(), new UpdateTaskData(null, null, null, [['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php']])))
        ->toThrow(ResourceOperationException::class);
});

it('records a correction resume without its implementer thread once and keeps it pending', function (): void {
    [$group, $task] = revalidation_fixture([['id' => 'implementation', 'type' => 'file', 'path' => 'app/Present.php']]);
    $comment = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => TaskCommentType::Resolution, 'author' => 'operator', 'body' => 'Resume.', 'posted_at' => now()]);
    $resume = ['comment_id' => $comment->id, 'thread_id' => 999_999, 'key' => 'correction-key', 'message' => 'Resume.', 'state' => 'pending'];
    $task->update(['status' => TaskStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'Invalid overlay path.', 'deliverable_correction_resume' => $resume]);

    app(ResumeDeliverableCorrectionAction::class)->execute($task);
    app(ResumeDeliverableCorrectionAction::class)->execute($task);

    $records = Activity::query()->where('subject_id', $task->id)->where('description', 'deliverable correction resume unavailable')->get();
    expect($records)->toHaveCount(1);
    expect($records->sole()->properties?->get('missing'))->toBe('implementer thread');
    expect($task->fresh()?->deliverable_correction_resume['state'] ?? null)->toBe('pending');
});

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});
