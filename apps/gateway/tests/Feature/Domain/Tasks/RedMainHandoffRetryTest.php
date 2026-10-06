<?php

declare(strict_types=1);

use App\Actions\Tasks\RetryTaskHandoffAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeAgentDriver;

use function Pest\Laravel\mock;

const HANDOFF_RED_HEAD = '328cd91b42156a3bb2d52c949574cfa30ada461c';
const HANDOFF_GREEN_TIP = 'f843defd6803b66f9a238c247ef4ee7ad2d0a6a2';

/** Historical 1288/check 1432 shape; all rows and remote boundaries are isolated fixtures. */
function handoff_retry_fixture(?FakeAgentDriver $driver = null): array
{
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:acme/orbit.git', 'default_branch' => 'main', 'task_check' => 'composer check']);
    $node = Node::query()->create(['name' => 'handoff', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.201', 'wireguard_ip' => '10.44.0.201']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1287', 'checkout_path' => '/isolated/task-1287', 'branch' => 'task-1287', 'status' => 'source_resolved', 'starting_commit' => HANDOFF_RED_HEAD]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => '1287 lifecycle', 'brief' => 'Preserve lifecycle work.', 'status' => 'running', 'execution_mode' => 'managed', 'assistance_requested' => true, 'assistance_kind' => 'failure']);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => '1288 lifecycle handoff', 'brief' => 'Historical CLI failure.', 'status' => 'running', 'assistance_requested' => true, 'assistance_kind' => 'failure', 'subtask_start_commit' => HANDOFF_RED_HEAD]);
    test_link_agent_threads($group);
    $task->refresh();
    $receipt = TaskComment::query()->create(['task_id' => $task->id, 'task_group_id' => $group->id, 'agent_thread_id' => $task->implementer_agent_thread_id, 'type' => 'ready_for_review', 'author' => 'implementer', 'body' => 'Lifecycle candidate ready.', 'posted_at' => now(), 'completion_attempt' => $task->completion_attempt, 'receipt_hash' => hash('sha256', '1288 receipt')]);
    TaskCheck::query()->create(['task_id' => $task->id, 'task_comment_id' => $receipt->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Failed, 'pid' => 1432, 'process_started' => 'historical', 'head_before' => HANDOFF_RED_HEAD, 'tree_before' => str_repeat('b', 40), 'started_at' => now(), 'exit_code' => 1]);
    $driver ??= new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(AgentSpawner::class, new NullAgentSpawner);
    $state = (object) ['head' => HANDOFF_RED_HEAD, 'tree' => str_repeat('b', 40), 'launches' => 0, 'loseReply' => false, 'reading' => TaskCheckReading::running(), 'payload' => null, 'tip' => HANDOFF_GREEN_TIP, 'ancestor' => true, 'runs' => [['name' => 'CLI', 'conclusion' => 'success']], 'snapshot' => null, 'advances' => 0, 'duringGreen' => null, 'duringMutation' => null];
    $checks = mock(TaskCheckRunner::class);
    $checks->shouldReceive('snapshot')->andReturnUsing(fn () => $state->snapshot instanceof Closure ? ($state->snapshot)() : new TaskWorkspaceSnapshot($state->head, $state->tree, branch: 'task-1287', indexTree: $state->tree));
    $checks->shouldReceive('start')->andReturnUsing(function (Instance $instance, ?string $command, array $setups, ?array $payload) use ($state): TaskCheckProcess {
        expect($command)->toBe('composer check')->and($setups)->toBe([]);
        if ($state->duringMutation instanceof Closure) {
            ($state->duringMutation)('launch');
        }
        $state->launches++;
        $state->payload = $payload;
        if ($state->loseReply) {
            throw new TaskCheckException('Reply lost after remote launch.');
        }

        return new TaskCheckProcess(5000, 'recovered', $state->head, $state->tree);
    });
    $checks->shouldReceive('read')->andReturnUsing(fn () => $state->reading);
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldReceive('fetchForTurn');
    $bases->shouldReceive('defaultTip')->andReturnUsing(fn () => $state->tip);
    $bases->shouldReceive('isAncestor')->andReturnUsing(fn () => $state->ancestor);
    $bases->shouldReceive('advanceCandidate')->andReturnUsing(function () use ($state): TaskWorkspaceSnapshot {
        if ($state->duringMutation instanceof Closure) {
            ($state->duringMutation)('advance');
        }
        $state->advances++;
        $state->head = HANDOFF_GREEN_TIP;
        $state->tree = str_repeat('c', 40);

        return new TaskWorkspaceSnapshot($state->head, $state->tree, branch: 'task-1287', indexTree: $state->tree);
    });
    $bases->shouldNotReceive('resetToDefault');
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_fixture'], 201),
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => function () use ($state) {
            if ($state->duringGreen instanceof Closure) {
                ($state->duringGreen)();
            }

            return Http::response(['check_runs' => $state->runs]);
        },
    ]);

    return [$task, $state, $driver];
}

it('durably recovers 1288 once and preserves its original proof base across restart', function (): void {
    [$task, $state] = handoff_retry_fixture();
    $task->update(['deliverables' => [['id' => 'regression', 'type' => 'command', 'command' => 'composer test', 'directory' => 'apps/cli', 'fails_on_base' => true, 'paths' => ['apps/cli/tests/RegressionTest.php']]]]);
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeFalse();
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeTrue();
    expect(app(RetryTaskHandoffAction::class)->recover($task->fresh()))->toBeTrue();
    expect($state->launches)->toBe(1)->and(TaskCheck::query()->count())->toBe(2);
    expect(TaskReviewBase::commit($task->fresh()))->toBe(HANDOFF_RED_HEAD);
    expect($state->payload['start'])->toBe(HANDOFF_RED_HEAD)
        ->and($state->payload['commands'][0]['fails_on_base'])->toBeTrue()
        ->and($state->payload['commands'][0]['paths'])->toBe(['apps/cli/tests/RegressionTest.php']);
    expect($task->fresh()->handoff_retry['advancedTree'])->toBe(str_repeat('c', 40));
});

it('fails closed after a lost launch reply and reconciles a durable check without another launch', function (): void {
    [$task, $state] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $state->loseReply = true;
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeFalse();
    expect(app(RetryTaskHandoffAction::class)->recover($task->fresh()))->toBeFalse();
    expect($state->launches)->toBe(1)->and($task->fresh()->assistance_requested)->toBeTrue();
    $intent = $task->fresh()->handoff_retry;
    TaskCheck::query()->create(['task_id' => $task->id, 'task_comment_id' => $intent['receiptId'], 'kind' => 'handoff', 'status' => 'running', 'pid' => 5000, 'process_started' => 'recovered', 'head_before' => $state->head, 'tree_before' => $state->tree, 'started_at' => now()]);
    expect(app(RetryTaskHandoffAction::class)->recover($task->fresh()))->toBeTrue();
    expect($state->launches)->toBe(1)->and($task->fresh()->handoff_retry['phase'])->toBe('running');
});

it('does not advance or launch after ownership candidate or worker movement', function (string $case): void {
    [$task, $state, $driver] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    match ($case) {
        'checkout' => $task->parent->taskable->update(['checkout_path' => '/moved/candidate']),
        'branch' => $task->parent->taskable->update(['branch' => 'another-candidate']),
        'owner' => $task->update(['implementer_agent_thread_id' => null]),
        'direction' => $task->update(['assistance_kind' => 'direction']),
        'attempt' => $task->update(['completion_attempt' => $task->completion_attempt + 1]),
        'worker' => $driver->observation = new AgentObservation(AgentThreadState::Working),
        'proof' => $task->update(['subtask_start_commit' => str_repeat('f', 40)]),
    };
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeFalse();
    expect($state->launches)->toBe(0)->and($state->head)->toBe(HANDOFF_RED_HEAD);
})->with(['owner', 'direction', 'attempt', 'worker', 'proof', 'checkout', 'branch']);

it('retains assistance without another launch for terminal failed lost changed or unproved checks', function (string $case): void {
    [$task, $state] = handoff_retry_fixture();
    if ($case === 'unproved') {
        $task->update(['deliverables' => [['id' => 'regression', 'type' => 'command', 'command' => 'composer test', 'directory' => '.']]]);
    }
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $state->reading = match ($case) {
        'failed' => TaskCheckReading::finished(1, HANDOFF_GREEN_TIP, str_repeat('c', 40), [], 'failed'),
        'lost' => TaskCheckReading::lost('lost'),
        'changed' => TaskCheckReading::finished(0, HANDOFF_GREEN_TIP, str_repeat('d', 40), ['changed'], 'changed'),
        'unproved' => TaskCheckReading::finished(0, HANDOFF_GREEN_TIP, str_repeat('c', 40), [], 'passed without evidence'),
    };
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeTrue();
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeFalse();
    expect($state->launches)->toBe(1)->and($task->fresh()->assistance_requested)->toBeTrue()
        ->and($task->fresh()->status->value)->toBe('running');
})->with(['failed', 'lost', 'changed', 'unproved']);

it('admits an unchanged passed recovered check through ordinary review', function (): void {
    [$task, $state] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $state->reading = TaskCheckReading::finished(0, HANDOFF_GREEN_TIP, str_repeat('c', 40), [], 'passed');
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeTrue();
    expect($task->fresh()->status->value)->toBe('reviewing')
        ->and($task->fresh()->assistance_requested)->toBeFalse()
        ->and($state->launches)->toBe(1);
});

it('refuses handoff intent for an active worker changed candidate or unverified descendant', function (string $case): void {
    [$task, $state, $driver] = handoff_retry_fixture();
    match ($case) {
        'worker' => $driver->observation = new AgentObservation(AgentThreadState::Working),
        'candidate' => $state->tree = str_repeat('f', 40),
        'diverged' => $state->ancestor = false,
        'unchanged' => $state->tip = HANDOFF_RED_HEAD,
        'empty' => $state->runs = [],
        'rollup' => $state->runs = [['name' => 'Required checks', 'conclusion' => 'success']],
        'infra' => $state->runs = [['name' => 'CLI', 'conclusion' => 'startup_failure']],
        'pending' => $state->runs = [['name' => 'CLI', 'conclusion' => null]],
        'red' => $state->runs = [['name' => 'CLI', 'conclusion' => 'failure']],
    };
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeFalse();
    expect($task->fresh()->handoff_retry)->toBeNull()->and($state->launches)->toBe(0);
})->with(['worker', 'candidate', 'diverged', 'unchanged', 'empty', 'rollup', 'infra', 'pending', 'red']);

it('revalidates pinned green evidence before advancing a persisted intent', function (string $case): void {
    [$task, $state] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    if ($case === 'ref moved') {
        $state->tip = str_repeat('f', 40);
    } else {
        $state->runs = [['name' => 'CLI', 'conclusion' => 'failure']];
    }
    expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeFalse();
    expect($state->head)->toBe(HANDOFF_RED_HEAD)->and($state->launches)->toBe(0);
})->with(['ref moved', 'red']);

it('refuses intent while another subtask in the same workspace has a running check', function (): void {
    [$task, $state] = handoff_retry_fixture();
    $other = Task::query()->create(['parent_id' => $task->parent_id, 'position' => 2, 'title' => 'Other', 'brief' => 'Another check', 'status' => 'todo']);
    TaskCheck::query()->create(['task_id' => $other->id, 'kind' => 'baseline', 'status' => 'running', 'pid' => 6000, 'process_started' => 'other', 'head_before' => HANDOFF_RED_HEAD, 'tree_before' => str_repeat('b', 40), 'started_at' => now()]);
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeFalse();
    expect($state->launches)->toBe(0);
});

it('rolls back interrupted completion and recovers the same passed check without launching again', function (string $point): void {
    [$task, $state] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $state->reading = TaskCheckReading::finished(0, HANDOFF_GREEN_TIP, str_repeat('c', 40), [], 'passed');
    $interrupt = true;
    $event = $point === 'before review' ? 'updating' : 'updated';
    Event::listen('eloquent.'.$event.': '.Task::class, function (Task $changed) use ($task, $point, &$interrupt): void {
        $matches = match ($point) {
            'task clear' => $changed->id === $task->id && ! $changed->assistance_requested,
            'group clear' => $changed->id === $task->parent_id && ! $changed->assistance_requested,
            'before review' => $changed->id === $task->id && $changed->isDirty('status') && $changed->status->value === 'reviewing',
        };
        if ($interrupt && $matches) {
            $interrupt = false;
            throw new RuntimeException('Interrupted completion at '.$point);
        }
    });
    expect(fn () => app(RetryTaskHandoffAction::class)->recover($task))->toThrow(RuntimeException::class, 'Interrupted completion');
    expect($task->fresh()->assistance_requested)->toBeTrue()
        ->and($task->parent()->firstOrFail()->assistance_requested)->toBeTrue()
        ->and($task->fresh()->status->value)->toBe('running')
        ->and($task->fresh()->handoff_retry['phase'])->toBe('running');
    expect(app(RetryTaskHandoffAction::class)->recover($task->fresh()))->toBeTrue();
    expect($task->fresh()->status->value)->toBe('reviewing')
        ->and($task->fresh()->handoff_retry['phase'])->toBe('finished')
        ->and($state->launches)->toBe(1);
})->with(['task clear', 'group clear', 'before review']);

it('preserves a late direction ownership or retry identity change during passed-check observation', function (string $change): void {
    $driver = new class('pi') extends FakeAgentDriver
    {
        public ?Closure $duringObservation = null;

        public function observe(AgentThread $thread): AgentObservation
        {
            if ($this->duringObservation instanceof Closure) {
                ($this->duringObservation)();
            }

            return parent::observe($thread);
        }
    };
    [$task, $state] = handoff_retry_fixture($driver);
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $state->reading = TaskCheckReading::finished(0, HANDOFF_GREEN_TIP, str_repeat('c', 40), [], 'passed');
    $posted = false;
    $driver->duringObservation = function () use ($task, &$posted, $change): void {
        if (! $posted && $task->checks()->latest('id')->first()->status === TaskCheckStatus::Passed) {
            $posted = true;
            if ($change === 'direction') {
                app(StoreTaskCommentAction::class)->execute($task->fresh(), ['type' => 'assistance_requested', 'author' => 'operator', 'body' => 'New direction during observation; do not admit review.']);
            } elseif ($change === 'owner') {
                $task->fresh()->update(['implementer_agent_thread_id' => null]);
            } else {
                $newIntent = $task->fresh()->handoff_retry;
                $newIntent['receiptId']++;
                $task->fresh()->update(['handoff_retry' => $newIntent]);
            }
        }
    };
    app(RetryTaskHandoffAction::class)->recover($task);
    expect($posted)->toBeTrue()
        ->and($task->fresh()->assistance_kind->value)->toBe($change === 'direction' ? 'direction' : 'failure')
        ->and($task->parent()->firstOrFail()->assistance_kind->value)->toBe($change === 'direction' ? 'direction' : 'failure')
        ->and($task->fresh()->status->value)->toBe('running')
        ->and($task->fresh()->handoff_retry['phase'])->toBe('running')
        ->and($state->launches)->toBe(1);
})->with(['direction', 'owner', 'retry identity']);

it('refuses advanced recovery when real Git branch or staging changes without changing HEAD or working tree', function (string $change): void {
    [$task, $state] = handoff_retry_fixture();
    $directory = sys_get_temp_dir().'/orbit-handoff-restart-'.bin2hex(random_bytes(6));
    $git = function (array $args) use ($directory): string {
        return trim((new Process(['git', '-C', $directory, '-c', 'user.name=test', '-c', 'user.email=test@example.test', ...$args]))->mustRun()->getOutput());
    };
    (new Process(['git', 'init', '-q', '-b', 'task-1287', $directory]))->mustRun();
    try {
        file_put_contents($directory.'/candidate', 'base');
        $git(['add', '.']);
        $git(['commit', '-qm', 'base']);
        $old = $git(['rev-parse', 'HEAD']);
        file_put_contents($directory.'/upstream', 'fix');
        $git(['add', '.']);
        $git(['commit', '-qm', 'green']);
        $target = $git(['rev-parse', 'HEAD']);
        $git(['reset', '--hard', $old]);
        file_put_contents($directory.'/candidate', 'preserved work');
        if ($change === 'unstage') {
            $git(['add', 'candidate']);
        }
        $state->snapshot = function () use ($directory): TaskWorkspaceSnapshot {
            $data = json_decode((new Process(['python3', resource_path('tasks/check'), 'snapshot', $directory]))->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            return new TaskWorkspaceSnapshot($data['head'], $data['tree'], branch: $data['branch'] ?? null, indexTree: $data['index_tree'] ?? null);
        };
        $before = ($state->snapshot)();
        $task->parent->taskable->update(['checkout_path' => $directory]);
        $task->update(['subtask_start_commit' => $old]);
        $task->checks()->update(['head_before' => $old, 'tree_before' => $before->tree]);
        $state->tip = $target;
        expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
        $git(['merge', '--ff-only', '--no-autostash', $target]);
        $advanced = ($state->snapshot)();
        $intent = $task->fresh()->handoff_retry;
        $intent['phase'] = 'advanced';
        $intent['advancedTree'] = $advanced->tree;
        $intent['advancedIndexTree'] = $advanced->indexTree;
        $task->update(['handoff_retry' => $intent]);
        match ($change) {
            'branch' => $git(['switch', '-c', 'unexpected-branch']),
            'stage' => $git(['add', 'candidate']),
            'unstage' => $git(['reset', 'HEAD', '--', 'candidate']),
        };
        $changed = ($state->snapshot)();
        expect($changed->head)->toBe($advanced->head)->and($changed->tree)->toBe($advanced->tree);
        expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeFalse();
        expect($state->launches)->toBe(0)->and($task->fresh()->assistance_requested)->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['branch', 'stage', 'unstage']);

it('does not admit advancement or launch after direction published during remote verification', function (string $boundary): void {
    $driver = new class('pi') extends FakeAgentDriver
    {
        public ?Closure $duringObservation = null;

        public function observe(AgentThread $thread): AgentObservation
        {
            if ($this->duringObservation instanceof Closure) {
                ($this->duringObservation)();
            }

            return parent::observe($thread);
        }
    };
    [$task, $state] = handoff_retry_fixture($driver);
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $posted = false;
    $post = function () use ($task, &$posted): void {
        if (! $posted) {
            $posted = true;
            app(StoreTaskCommentAction::class)->execute($task->fresh(), ['type' => 'assistance_requested', 'author' => 'operator', 'body' => 'Hold before the next remote mutation.']);
        }
    };
    if ($boundary === 'green verification') {
        $state->duringGreen = $post;
    } else {
        $driver->duringObservation = function () use ($task, $post): void {
            if ($task->fresh()->handoff_retry['phase'] === 'advanced') {
                $post();
            }
        };
    }

    app(RetryTaskHandoffAction::class)->recover($task);

    expect($posted)->toBeTrue();
    expect($state->advances)->toBe($boundary === 'green verification' ? 0 : 1)
        ->and($state->launches)->toBe(0);
    expect($task->fresh()->assistance_kind->value)->toBe('direction')
        ->and($task->parent()->firstOrFail()->assistance_kind->value)->toBe('direction')
        ->and($task->fresh()->handoff_retry['phase'])->toBe($boundary === 'green verification' ? 'prepared' : 'advanced');
})->with(['green verification', 'last worker observation']);

it('publishes direction under admission before recovery and releases on publication failure', function (bool $fail): void {
    [$task, $state] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $directory = sys_get_temp_dir().'/orbit-direction-admission-'.bin2hex(random_bytes(6));
    app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
    $transactionLevel = DB::transactionLevel();
    $attempts = 0;
    Event::listen('eloquent.creating: '.TaskComment::class, function (TaskComment $comment) use ($task, $directory, $transactionLevel, $fail, &$attempts): void {
        if ($comment->getRawOriginal('type') !== 'assistance_requested' && $comment->type->value !== 'assistance_requested') {
            return;
        }
        $attempts++;
        $independent = fopen($directory.'/task-'.$task->parent_id.'.lock', 'c+');
        try {
            expect(flock($independent, LOCK_EX | LOCK_NB))->toBeFalse()
                ->and(DB::transactionLevel())->toBe($transactionLevel + 1);
        } finally {
            fclose($independent);
        }
        if ($fail && $attempts === 1) {
            throw new RuntimeException('Publication interrupted.');
        }
    });
    $publish = fn () => app(StoreTaskCommentAction::class)->execute($task->fresh(), ['type' => 'assistance_requested', 'author' => 'operator', 'body' => 'Direction wins admission.']);
    try {
        if ($fail) {
            expect($publish)->toThrow(RuntimeException::class, 'Publication interrupted.');
            expect($task->comments()->where('type', 'assistance_requested')->count())->toBe(0)
                ->and($task->fresh()->assistance_kind->value)->toBe('failure');
            $independent = fopen($directory.'/task-'.$task->parent_id.'.lock', 'c+');
            try {
                expect(flock($independent, LOCK_EX | LOCK_NB))->toBeTrue();
            } finally {
                flock($independent, LOCK_UN);
                fclose($independent);
            }
        }
        $publish();

        expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeFalse();
        expect($state->advances)->toBe(0)->and($state->launches)->toBe(0);
        expect($task->fresh()->assistance_kind->value)->toBe('direction')
            ->and($task->comments()->where('type', 'assistance_requested')->count())->toBe(1);
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['normal publication' => false, 'interrupted publication' => true]);

it('finishes admitted remote work before an independent publisher can acquire admission', function (string $boundary): void {
    [$task, $state] = handoff_retry_fixture();
    expect(app(RetryTaskHandoffAction::class)->queue($task))->toBeTrue();
    $directory = sys_get_temp_dir().'/orbit-handoff-admission-'.bin2hex(random_bytes(6));
    app()->instance(TaskExecutionLock::class, new NativeTaskExecutionLock($directory));
    $transactionLevel = DB::transactionLevel();
    $contender = null;
    $state->duringMutation = function (string $operation) use ($task, $directory, $transactionLevel, $boundary, &$contender): void {
        expect(DB::transactionLevel())->toBe($transactionLevel);
        if ($operation !== $boundary) {
            return;
        }
        $independent = fopen($directory.'/task-'.$task->parent_id.'.lock', 'c+');
        try {
            expect(flock($independent, LOCK_EX | LOCK_NB))->toBeFalse();
        } finally {
            fclose($independent);
        }
        // A separate process probes the same admission a direction publisher must acquire.
        $code = <<<'PHP'
require $argv[1];
$lock = new App\Infrastructure\Tasks\NativeTaskExecutionLock($argv[2]);
fwrite(STDOUT, "waiting\n");
$lock->synchronized((int) $argv[3], function () use ($argv): void {
    file_put_contents($argv[2].'/publisher-admitted', 'yes');
});
PHP;
        $contender = new Process([PHP_BINARY, '-r', $code, base_path('vendor/autoload.php'), $directory, (string) $task->parent_id]);
        $contender->setTimeout(5);
        $contender->start();
        $contender->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'waiting'));
        expect($contender->isRunning())->toBeTrue()
            ->and(is_file($directory.'/publisher-admitted'))->toBeFalse();
    };
    try {
        expect(app(RetryTaskHandoffAction::class)->recover($task))->toBeTrue();
        expect($contender)->toBeInstanceOf(Process::class);
        $contender->wait();
        expect($contender->isSuccessful())->toBeTrue($contender->getErrorOutput())
            ->and(is_file($directory.'/publisher-admitted'))->toBeTrue();
        app(StoreTaskCommentAction::class)->execute($task->fresh(), ['type' => 'assistance_requested', 'author' => 'operator', 'body' => 'Direction follows admitted work.']);

        expect(app(RetryTaskHandoffAction::class)->recover($task->fresh()))->toBeFalse();
        expect($state->advances)->toBe(1)->and($state->launches)->toBe(1);
        expect($task->fresh()->assistance_kind->value)->toBe('direction')
            ->and($task->fresh()->handoff_retry['phase'])->toBe('running');
    } finally {
        $contender?->stop();
        File::deleteDirectory($directory);
    }
})->with(['advance', 'launch']);
