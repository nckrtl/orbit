<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\NullTaskReviewDiff;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\PrunePendingTaskThreads;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskBranchUpdate;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGitHubReviewConsumption;
use App\Domain\Tasks\TaskGitHubReviewFeedback;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestDescription;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewFindingsPacket;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskSettlingFixup;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\JevDecision;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Tests\Feature\Domain\Tasks\ApprovalObservationFixtures as FeedbackFixtures;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskPullRequestReviewWatcher;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\FakeTaskWorkspaceTopology;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    test_bind_snapshot_driver();
});

function tick_group(): Task
{
    $project = Project::query()->create([
        'name' => 'tick-app',
        'slug' => 'tick-app',
        'repository_url' => 'git@example.test:tick.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'tick-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.212',
        'wireguard_ip' => '10.44.0.212',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/tick-app/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Tick routing',
        'brief' => 'Observe, classify, and execute.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
        'started_at' => now(),
    ]);

    test_link_agent_threads($group);

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/** @return array{thread: array<string, mixed>} */
function tick_checked_thread(string $status): array
{
    return ['thread' => [
        'session' => ['status' => $status],
        'activities' => [[
            'id' => 'check-1',
            'kind' => 'command.completed',
            'output' => 'composer check',
            'exitCode' => 0,
            'createdAt' => '2026-09-22T12:00:00Z',
        ]],
    ]];
}

function tick_workspace(?string $branch = null): void
{
    app()->instance(TaskWorkspaceStateReader::class, new readonly class($branch) implements TaskWorkspaceStateReader
    {
        public function __construct(private ?string $branch) {}

        public function headCommit(Instance $instance): ?string
        {
            return null;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return $this->branch;
        }
    });
}

/** @param object{turnId: string, error: string} $state */
function tick_pi_failure(object $state): void
{
    Http::fake(function (Request $request) use ($state) {
        if (str_ends_with($request->url(), '/messages')) {
            return Http::response(['duplicate' => false], 202);
        }
        if (! str_contains($request->url(), '/sessions/implementer-thread')) {
            return null;
        }

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => 'implementer-thread'],
            'state' => isset($state->state) ? $state->state : 'failed', 'error' => $state->error, 'turnId' => $state->turnId, 'entries' => [],
        ]);
    });
}

/** @return list<string> */
function tick_pi_message_keys(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/messages'))
        ->map(fn (Request $request): string => (string) $request['key'])
        ->values()
        ->all();
}

function tick_pi_implementer(): Task
{
    $group = tick_group();
    $other = new FakeAgentDriver('example');
    $other->observation = new AgentObservation(AgentThreadState::Idle);
    AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->update(['driver' => 'example']);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$other, app(PiDriver::class)]));
    $task = $group->tasks->sole();
    $thread = AgentThread::query()->findOrFail($task->implementer_agent_thread_id);
    $thread->update(['driver' => 'pi']);
    $thread->node?->update([
        'settings' => ['pi' => ['token' => 'pi-node-token-with-more-than-32-characters', 'url' => 'http://10.44.0.212:3774']],
    ]);
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });

    return $task;
}

function tick_dispatcher(): AgentCommandDispatcher
{
    return new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
}

/** @return array{0: Task, 1: Task, 2: TaskComment, 3: TaskQuestion} */
function tick_held_relay(): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    $resolution = TaskComment::query()->create([
        'task_id' => $task->id, 'task_group_id' => $group->id, 'type' => 'resolution',
        'body' => 'Use the public mirror.', 'author' => 'operator', 'posted_at' => now(),
    ]);
    $task->update([
        'direction_relay_comment_id' => $resolution->id,
        'assistance_requested' => false, 'assistance_kind' => null, 'assistance_reason' => null,
    ]);
    $group->update(['assistance_requested' => false, 'assistance_kind' => null, 'assistance_reason' => null]);
    $question = TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Reviewer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
        'resolution_comment_id' => $resolution->id,
    ]);

    return [$group->fresh(['project', 'tasks', 'taskable']) ?? $group, $task->fresh() ?? $task, $resolution, $question];
}

function tick_relay_runtime(FakeTaskTurnReceipts $receipts, AgentCommandDispatcher $dispatcher, object $state): void
{
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    $reader = new class($state) implements AgentSnapshotReader
    {
        public function __construct(private object $state) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId === 'implementer-thread') {
                $turnId = isset($this->state->turnId) && is_string($this->state->turnId) ? $this->state->turnId : '';
                $messageId = isset($this->state->messageId) && is_string($this->state->messageId) ? $this->state->messageId : null;
                $thread = ['session' => ['status' => $this->state->implementer]];
                if ($turnId !== '') {
                    $thread['latestTurn'] = ['id' => $turnId, 'state' => 'done'];
                }
                if ($messageId !== null) {
                    $thread['messages'] = [['id' => $messageId, 'role' => 'user', 'text' => 'Accepted.', 'createdAt' => '2026-10-08T00:00:00Z']];
                }

                return ['thread' => $thread];
            }

            $reviewer = isset($this->state->reviewer) && is_string($this->state->reviewer) ? $this->state->reviewer : 'done';

            return ['thread' => [
                'session' => ['status' => $reviewer],
                'latestTurn' => ['id' => $this->state->reviewerTurnId ?? 'relay-turn', 'state' => 'done'],
            ]];
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));
}

/**
 * A consult whose reviewer turn id becomes the command id of the accepted send.
 *
 * @return array{0: Task, 1: Task, 2: FakeTaskTurnReceipts, 3: AgentCommandDispatcher, 4: object}
 */
function tick_consult_exchange(bool $loseResponse, ?array $receiptContents = null): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    $answer = 'Yes. The contract allows the intl extension.';
    $receipts = new FakeTaskTurnReceipts($receiptContents ?? [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', $answer, cause: 'missed_contract'),
        null,
    ]);
    $state = (object) [
        'implementer' => 'done',
        'reviewer' => 'done',
        'turnId' => '',
        'messageId' => null,
        'reviewerTurnId' => '',
        'reviewerError' => null,
        'loseResponse' => $loseResponse,
        'unavailable' => false,
    ];
    $dispatcher = new class($state) implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function __construct(private object $state) {}

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) !== 'implementer-thread') {
                $this->state->reviewerTurnId = (string) ($command['commandId'] ?? '');
                if ($this->state->loseResponse === true) {
                    $this->state->loseResponse = false;

                    throw new AgentDriverException('response lost');
                }
            }
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->state->turnId = (string) ($command['commandId'] ?? '');
                $this->state->messageId = (string) ($command['commandId'] ?? '');
                if (($this->state->loseImplementerResponse ?? false) === true) {
                    $this->state->loseImplementerResponse = false;

                    throw new AgentDriverException('implementer response lost');
                }
            }

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    $reader = new class($state) implements AgentSnapshotReader
    {
        public function __construct(private object $state) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($this->state->unavailable === true) {
                return null;
            }
            if ($threadId === 'implementer-thread') {
                $turnId = is_string($this->state->turnId) ? $this->state->turnId : '';
                $thread = ['session' => ['status' => $this->state->implementer]];
                if ($turnId !== '') {
                    $thread['latestTurn'] = ['id' => $turnId, 'state' => 'done'];
                }
                if (is_string($this->state->messageId) && $this->state->messageId !== '') {
                    $thread['messages'] = [['id' => $this->state->messageId, 'role' => 'user', 'text' => 'Accepted.', 'createdAt' => '2026-10-08T00:00:00Z']];
                }

                return ['thread' => $thread];
            }
            $failed = $this->state->reviewer === 'error';
            $thread = [
                'session' => ['status' => $this->state->reviewer, 'lastError' => $this->state->reviewerError],
                'error' => $this->state->reviewerError,
            ];
            if (is_string($this->state->reviewerTurnId) && $this->state->reviewerTurnId !== '') {
                $thread['latestTurn'] = [
                    'id' => $this->state->reviewerTurnId,
                    'state' => $failed ? 'error' : 'done',
                    'error' => $this->state->reviewerError,
                ];
                $thread['messages'] = [['id' => $this->state->reviewerTurnId, 'role' => 'user', 'text' => 'Consult.', 'createdAt' => '2026-10-08T00:00:00Z']];
            }

            return ['thread' => $thread];
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));

    return [$group, $task, $receipts, $dispatcher, $state];
}

/** @return list<array<string, mixed>> */
function tick_reviewer_starts(AgentCommandDispatcher $dispatcher): array
{
    return array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) !== 'implementer-thread',
    ));
}

beforeEach(function (): void {
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    tick_workspace();
});

it('deletes stale pending thread rows for finished tasks', function (): void {
    $group = tick_group();
    $task = $group->tasks->firstOrFail();
    $task->update(['status' => TaskStatus::Completed]);
    $pending = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 't3',
        'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'pending:stale',
        'role' => 'reviewer', 'node_id' => $group->taskable->node_id,
    ]);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect(AgentThread::query()->find($pending->id))->toBeNull();
});

it('retains pending reservations for active tasks so spawn retries can reuse them', function (): void {
    $group = tick_group();
    $task = $group->tasks->firstOrFail();
    $task->update(['status' => TaskStatus::Running]);
    $pending = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 't3',
        'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'pending:active-reservation',
        'role' => 'implementer', 'node_id' => $group->taskable->node_id,
    ]);
    $pending->forceFill(['created_at' => now()->subDays(10)])->save();

    app(PrunePendingTaskThreads::class)->run();

    expect($pending->fresh())->not->toBeNull();
});

it('flags a prior settling group without a reviewed PR once and retains its workspace', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once()->withArgs(
        fn (Task $blocked, string $reason): bool => $blocked->id === $group->id && str_contains($reason, 'no reviewed pull request URL'),
    );
    Http::preventStrayRequests();

    app(TaskScheduler::class)->settle($group);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $group->id, 'status' => 'settling', 'pr_url' => null,
        'assistance_requested' => true, 'taskable_id' => $group->taskable_id, 'settled_at' => null,
    ]);
    Http::assertNothingSent();
});

it('resumes a settling group without a pull request and opens the pull request when that subtask is approved', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $reason = TaskScheduler::MissingPullRequestPrefix.' Cancel the group to push its approved commits to task-'.$group->id.' and remove its workspace.';
    $group->update([
        'status' => TaskGroupStatus::Settling,
        'pr_url' => null,
        'assistance_requested' => true,
        'assistance_reason' => $reason,
    ]);
    $task->update(['status' => TaskStatus::Todo, 'started_at' => null]);
    app(TaskExtensionState::class)->enable();
    $agents = tick_running_agents();
    $notifier = tick_assistance_notifier();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->pr_url)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and($notifier->reasons)->toBe([])
        ->and($agents->spawned)->toBe([$task->id])
        ->and($agents->missingRefOk)->toBeTrue()
        ->and($agents->fastForwards)->toBe(1);

    $task->refresh();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $task->review_attempt,
        'review_notified_turn_id' => 'handoff-turn',
        ...tick_review_baseline(),
    ]);
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });
    tick_workspace(branch: 'task-'.$group->id);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([tick_final_approval()]));
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public function commit(Instance $instance, string $message): ?string
        {
            $this->messages[] = $message;
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = str_repeat('c', 40);
            }

            return str_repeat('c', 40);
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publishing->publisher->pushes)->toBe([$group->id])
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskTurnPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 1, $group->project->taskCheckCommand())])
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('does not resume a settling group without a pull request while another assistance cause is set', function (): void {
    $group = tick_group();
    $group->update([
        'status' => TaskGroupStatus::Settling,
        'assistance_requested' => true,
        'assistance_reason' => 'Workspace removal failed: disk full',
    ]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $todo = Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Waiting', 'brief' => 'Stay todo.', 'status' => TaskStatus::Todo,
        'deliverables' => [[
            'id' => 'composer-check', 'type' => 'command', 'description' => 'Run composer check',
            'command' => 'composer check', 'directory' => '.',
        ]],
    ]);
    app(TaskExtensionState::class)->enable();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe('Workspace removal failed: disk full')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([])
        ->and($agents->fastForwards)->toBe(0);
});

it('continues watching a prior settling PR and completes only after it merges', function (): void {
    $group = tick_group();
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    GitHubTestSupport::storeApp();
    mock(InstanceRemover::class)->shouldReceive('execute')->once()->withArgs(
        fn (Instance $instance, bool $force): bool => $instance->id === $group->taskable_id && $force,
    )->andReturnUsing(function (Instance $instance): InstanceRemoval {
        $instance->delete();

        return new InstanceRemoval;
    });
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open'])
            ->push(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'pr_url' => $group->pr_url]);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'pr_url' => $group->pr_url, 'taskable_id' => null]);
    $this->assertDatabaseMissing('instances', ['id' => $group->taskable_id]);
    Http::assertSentCount(6);
});

/** A settling group whose pull request the tick reads through the faked GitHub App. */
function tick_settling_group(): Task
{
    $group = tick_group();
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    GitHubTestSupport::storeApp();

    return $group;
}

/** A subtask an operator appended. Its fixup identity stays null, so the cap ignores it. */
function tick_appended_subtask(Task $group): Task
{
    return Task::query()->create([
        'parent_id' => $group->id,
        'position' => ((int) $group->tasks()->max('position')) + 1,
        'title' => 'Address the finding',
        'brief' => 'Fix the review.',
        'status' => TaskStatus::Todo,
        'deliverables' => [[
            'id' => 'composer-check', 'type' => 'command', 'description' => 'Run composer check',
            'command' => 'composer check', 'directory' => '.',
        ]],
    ]);
}

/** One earlier fixup. Every status counts toward the cap. An approved commit, when given, is what the fixup pushed. */
function tick_spent_fixup(Task $group, string $problem, TaskStatus $status = TaskStatus::Completed, ?string $headSha = null, ?string $commit = null): Task
{
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => ((int) $group->tasks()->max('position')) + 1,
        'title' => 'Earlier fix',
        'brief' => 'Already tried.',
        'status' => $status,
        'fixup_problem' => $problem,
        'fixup_head_sha' => $headSha,
        'deliverables' => [[
            'id' => 'composer-check', 'type' => 'command', 'description' => 'Run composer check',
            'command' => 'composer check', 'directory' => '.',
        ]],
    ]);
    if ($commit !== null) {
        TaskComment::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id, 'type' => 'approved', 'body' => 'Approved.',
            'author' => 'reviewer', 'review_attempt' => 1, 'commit_sha' => $commit, 'posted_at' => now(),
        ]);
    }

    return $task;
}

/** @param  array<string, mixed>  $overrides */
function tick_open_pull(array $overrides = []): array
{
    return [
        'merged' => false,
        'state' => 'open',
        'mergeable' => true,
        'mergeable_state' => 'clean',
        'head' => ['sha' => 'abc123'],
        'base' => ['ref' => 'main'],
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $pulls
 * @param  array<string, list<array<string, mixed>>>  $checks  check runs keyed by head sha
 */
function tick_watch_pulls(array $pulls, array $checks = [], int $updateStatus = 422, string $updateMessage = 'merge conflict between base and head'): void
{
    $sequence = Http::sequence();
    foreach ($pulls as $body) {
        $sequence->push($body);
    }

    $fake = [
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => $sequence,
        'https://api.github.com/repos/acme/orbit/pulls/42/update-branch' => Http::response(['message' => $updateMessage], $updateStatus),
    ];
    foreach ($checks as $sha => $runs) {
        $fake['https://api.github.com/repos/acme/orbit/commits/'.$sha.'/check-runs*'] = Http::response(['total_count' => count($runs), 'check_runs' => $runs]);
    }
    Http::preventStrayRequests();
    Http::fake($fake);
}

/** @return object{spawned: list<int>, fetched: list<string>, events: list<string>, turnFetches: int, fastForwards: int, missingRefOk: bool} */
function tick_running_agents(bool $fetchFails = false, bool $fastForwardFails = false): object
{
    $agents = new class($fetchFails, $fastForwardFails) implements AgentSpawner, TaskBaseBranchFetcher
    {
        public int $turnFetches = 0;

        public int $fastForwards = 0;

        public bool $missingRefOk = false;

        /** @var list<int> */
        public array $spawned = [];

        /** @var list<string> */
        public array $fetched = [];

        /** @var list<string> */
        public array $events = [];

        public function __construct(private bool $fetchFails, private bool $fastForwardFails = false) {}

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->spawned[] = $task->id;
            $this->events[] = 'spawn';

            return test_agent_thread($task->parent, 'fixup-implementer-'.$task->id, $task)->id;
        }

        public function requestReview(Task $task): void {}

        public function fetch(Task $group, string $base): void
        {
            $this->fetched[] = $base;
            $this->events[] = 'fetch';
            if ($this->fetchFails) {
                throw new TaskPullRequestException('The base branch could not be fetched.');
            }
        }

        public function fastForward(Task $group, bool $missingRefOk = false): void
        {
            $this->fastForwards++;
            $this->missingRefOk = $missingRefOk;
            $this->events[] = 'fast-forward';
            if ($this->fastForwardFails) {
                throw new TaskPullRequestException('The task branch could not be fetched.');
            }
        }

        public function resetToDefault(Task $group): string
        {
            return str_repeat('c', 40);
        }

        public function fetchForTurn(Task $group): void
        {
            $this->turnFetches++;
            $this->events[] = 'turn-fetch';
            if ($this->fetchFails) {
                throw new TaskPullRequestException('The base branch could not be fetched.');
            }
        }
    };
    app()->instance(AgentSpawner::class, $agents);
    app()->instance(TaskBaseBranchFetcher::class, $agents);

    return $agents;
}

/** @return CoderSettleNotifier&object{reasons: list<string>} */
function tick_assistance_notifier(): CoderSettleNotifier
{
    $notifier = new class implements CoderSettleNotifier
    {
        /** @var list<string> */
        public array $reasons = [];

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void
        {
            $this->reasons[] = $reason;
        }
    };
    app()->instance(CoderSettleNotifier::class, $notifier);

    return $notifier;
}

it('preserves unresolved review assistance while CI and conflict fixes activate and continue', function (bool $conflict): void {
    mock(TaskPullRequestUpdater::class)->shouldReceive('updateBranch')->andReturn(TaskBranchUpdate::Conflict);
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    $source->candidateStatus = TaskReviewReadStatus::Unreadable;
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    $reason = 'The trusted review source remains unreadable (review:101:findings). Restore review-read access.';
    app(TaskGitHubReviewFeedback::class)->change($group, ['review:101:findings' => $reason]);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(new TaskPullRequestHealth(
        'open', ['Repair needed'], baseRef: 'main', conflicts: $conflict,
        failedChecks: $conflict ? [] : [new TaskPullRequestCheck('Gateway', null)], headSha: 'abc123',
    ));
    $agents = tick_running_agents();
    $snapshots = new class implements AgentSnapshotReader
    {
        public int $reads = 0;

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->reads++;

            return tick_checked_thread('running');
        }
    };
    app()->instance(AgentSnapshotReader::class, $snapshots);
    app(TaskScheduler::class)->tick();
    $fixup = $group->tasks()->whereNotNull('fixup_problem')->sole();
    expect($fixup->status)->toBe(TaskStatus::Running)
        ->and($agents->spawned)->toBe([$fixup->id])
        ->and($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toBe(TaskGitHubReviewFeedback::Prefix.$reason);
    app(TaskScheduler::class)->tick();
    expect($snapshots->reads)->toBeGreaterThan(0)
        ->and($group->fresh()->assistance_reason)->toBe(TaskGitHubReviewFeedback::Prefix.$reason)
        ->and($fixup->fresh()->status)->toBe(TaskStatus::Running)
        ->and(app(TaskGitHubReviewFeedback::class)->causes($group))->toBe(['review:101:findings' => $reason]);
})->with(['CI' => false, 'conflict' => true]);

it('starts one feedback fixup after a workspace retry and preserves its consumption through restart', function (): void {
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(new TaskPullRequestHealth('open', headSha: 'abc123'));
    $failedAgents = tick_running_agents(fetchFails: true);
    app(TaskScheduler::class)->tick();
    $fixup = $group->tasks()->where('fixup_problem', 'review:42')->sole();
    expect($fixup->status)->toBe(TaskStatus::Todo)->and($failedAgents->spawned)->toBe([]);
    $this->travel(60)->seconds();
    $agents = tick_running_agents();
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();
    expect($fixup->fresh()->status)->toBe(TaskStatus::Running)
        ->and($agents->spawned)->toBe([$fixup->id])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1)
        ->and($source->candidates)->toBe(1);
});

it('recovers a crash after feedback activation without consuming or appending again', function (): void {
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(new TaskPullRequestHealth('open', headSha: 'abc123'));
    tick_running_agents();
    mock(AgentSpawner::class)->shouldReceive('spawnImplementer')->once()->andThrow(new RuntimeException('Fixture crash after activation.'));
    expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class, 'Fixture crash after activation.');
    $fixup = $group->tasks()->where('fixup_problem', 'review:42')->sole();
    expect($fixup->status)->toBe(TaskStatus::Running)
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    $agents = tick_running_agents();
    app(TaskScheduler::class)->tick();
    expect($agents->spawned)->toBe([$fixup->id])
        ->and($source->candidates)->toBe(1)
        ->and($group->tasks()->where('fixup_problem', 'review:42')->count())->toBe(1);
});

it('retains consumption but skips feedback spawning when the PR closes after append', function (): void {
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(
        new TaskPullRequestHealth('open', headSha: 'abc123'),
        new TaskPullRequestHealth('closed', headSha: 'abc123'),
    );
    $agents = tick_running_agents();
    app(TaskScheduler::class)->tick();
    expect($group->tasks()->where('fixup_problem', 'review:42')->sole()->status)->toBe(TaskStatus::Cancelled)
        ->and($agents->spawned)->toBe([])
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Settling);
});

it('publishes a consumed feedback fixup once to the same PR and requires fresh external re-review', function (): void {
    [$group, $task, , $signer, $publisher] = tick_review([
        FakeTaskTurnReceipts::contents('approved', deliverables: ['review-findings' => 'Every immutable finding was addressed.']),
    ], last: true);
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git', 'task_check' => null]);
    $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/42']);
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    $candidate = $source->reviewCandidate($group, 101)->candidate;
    expect($candidate)->not->toBeNull();
    $plan = TaskSettlingFixup::reviewPlan(null, TaskReviewFindingsPacket::fromCandidate($candidate));
    $task->update(['fixup_problem' => $plan->identity, 'fixup_head_sha' => 'abc123', 'brief' => $plan->brief, 'deliverables' => $plan->deliverables]);
    DB::transaction(fn () => app(TaskGitHubReviewConsumption::class)->record($group, $task, $candidate, $group->tasks()->get()));
    $watcher = mock(TaskPullRequestWatcher::class);
    $watcher->shouldReceive('status')->andReturn('open');
    $watcher->shouldReceive('health')->andReturn(new TaskPullRequestHealth('open', headSha: str_repeat('c', 40)));
    $publisher->pushFailures = 1;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($signer->messages)->toHaveCount(1)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40));
    $this->travel(60)->seconds();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->bodies)->toBe([])
        ->and($publisher->pushes)->toBe([$group->id, $group->id])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Settling);
    app(TaskScheduler::class)->tick();
    expect(DB::table('task_github_review_consumptions')->count())->toBe(1);
    $source->observation = FeedbackFixtures::observation([FeedbackFixtures::review(102, head: str_repeat('c', 40))], head: str_repeat('c', 40));
    app(TaskScheduler::class)->tick();
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    $source->observation = FeedbackFixtures::observation([FeedbackFixtures::review(103, GitHubReviewState::ChangesRequested, str_repeat('c', 40))], head: str_repeat('c', 40));
    tick_running_agents();
    app(TaskScheduler::class)->tick();
    expect(DB::table('task_github_review_consumptions')->count())->toBe(2)
        ->and($group->tasks()->where('fixup_problem', 'review:42')->count())->toBe(2);
});

it('asks for assistance once per set of pull request problems and withdraws it when the pull request is healthy', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Cancelled);
    tick_spent_fixup($group, 'check:Rust agent');
    tick_spent_fixup($group, 'check:Rust agent', TaskStatus::Failed);
    $notifier = tick_assistance_notifier();
    $conflict = ['merged' => false, 'state' => 'open', 'mergeable' => false, 'mergeable_state' => 'dirty', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']];
    $clean = ['merged' => false, 'state' => 'open', 'mergeable' => true, 'mergeable_state' => 'clean', 'head' => ['sha' => 'def456'], 'base' => ['ref' => 'main']];
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42/update-branch' => Http::response(['message' => 'merge conflict between base and head'], 422),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push($conflict)->push($conflict)
            ->push([...$clean, 'mergeable_state' => 'unstable'])
            ->push($clean),
        'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs*' => Http::response(['total_count' => 0, 'check_runs' => []]),
        'https://api.github.com/repos/acme/orbit/commits/def456/check-runs*' => Http::sequence()
            ->push(['total_count' => 1, 'check_runs' => [['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1']]])
            ->push(['total_count' => 1, 'check_runs' => [['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/2']]]),
    ]);
    $conflictReason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push. Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).';
    $checkReason = 'The pull request needs attention: Check Rust agent failed: https://github.com/acme/orbit/runs/1. Orbit reached the cap of 2 fixups for check:Rust agent in the current window (2 counted).';

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_question' => null, 'assistance_reason' => $conflictReason]);
    $requestedAt = $group->fresh()?->updated_at;
    $this->travel(1)->minute();

    app(TaskScheduler::class)->tick();
    expect($notifier->reasons)->toBe([$conflictReason])
        ->and($group->fresh()?->updated_at?->equalTo($requestedAt))->toBeTrue();

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_question' => null, 'assistance_reason' => $checkReason]);
    expect($notifier->reasons)->toBe([$conflictReason, $checkReason]);

    // A re-run on the same head commit is read once the minute-long check cache expires.
    $this->travel(61)->seconds();
    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => false, 'assistance_kind' => null, 'assistance_question' => null, 'assistance_reason' => null]);
    expect($notifier->reasons)->toHaveCount(2)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(5);
});

it('leaves another cause of assistance on a settling group alone while its pull request conflicts or recovers', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    $notifier = tick_assistance_notifier();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => false, 'base' => ['ref' => 'main']])
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => true, 'base' => ['ref' => 'main']]),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    expect($notifier->reasons)->toBe([]);
});

it('does not replace an open direction request when the pull request needs attention', function (): void {
    $group = tick_settling_group();
    $question = 'Which base should the conflict follow?';
    $reason = "The reviewer is blocked: The pull request conflicts.\n\nQuestion: {$question}";
    $group->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    $notifier = tick_assistance_notifier();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => false, 'base' => ['ref' => 'main']])
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => true, 'base' => ['ref' => 'main']]),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and($notifier->reasons)->toBe([]);
});

it('withdraws its pull request assistance request when the pull request merges', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.']);
    mock(InstanceRemover::class)->shouldReceive('execute')->once()->andReturn(new InstanceRemoval);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'assistance_requested' => false, 'assistance_reason' => null]);
});

it('keeps another assistance reason without asking when the pull request merges', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'The operator asked to hold this group.']);
    mock(InstanceRemover::class)->shouldReceive('execute')->once()->andReturn(new InstanceRemoval);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'assistance_requested' => false, 'assistance_reason' => 'The operator asked to hold this group.']);
});

it('backs off a merged pull request cleanup and retries it on a later tick', function (): void {
    $group = tick_settling_group();
    $calls = 0;
    mock(InstanceRemover::class)->shouldReceive('execute')->andReturnUsing(function () use (&$calls): never {
        $calls++;

        throw new RuntimeException('disk full');
    });
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($calls)->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_reason)->toBe('Merged pull request cleanup failed: disk full');

    $this->travel(TaskScheduler::AbandonedWorkspaceBackoffSeconds + 1)->seconds();
    app(TaskScheduler::class)->tick();

    expect($calls)->toBe(2)
        ->and($group->fresh()?->taskable_id)->toBe($group->taskable_id);
});

it('preserves labeled merge evidence and report metrics when cleanup retries lose GitHub evidence', function (): void {
    $group = tick_settling_group();
    $task = $group->tasks->sole();
    $changes = [$task->title];
    $decision = JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'task_group_id' => $group->id,
        'task_ids' => [$task->id],
        'approval_comment_id' => 901,
        'approval_changes' => $changes,
        'approval_changes_digest' => hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        'approval_changes_redacted' => false,
        'call_started_at' => now()->subMinute()->toIso8601String(),
        'questions' => ['subtask_'.$task->id => []],
        'input_state' => ['subtasks' => [['title' => $task->title]], 'pull_request' => ['changes' => $changes]],
        'answers' => ['subtask_'.$task->id => ['value' => true, 'selected_answer_probability' => 0.97]],
        'latency_ms' => 25,
    ]);
    mock(InstanceRemover::class)->shouldReceive('execute')->andThrow(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push([
                'merged' => true, 'state' => 'closed', 'head' => ['sha' => 'head-sha'],
                'body' => "## Changes\n\n- {$task->title}\n", 'merge_commit_sha' => 'actual-merge-sha', 'merged_at' => '2026-10-01T10:00:00Z',
            ])
            ->push([
                'merged' => true, 'state' => 'closed', 'head' => ['sha' => 'head-sha'],
                'merge_commit_sha' => 'actual-merge-sha', 'merged_at' => '2026-10-01T10:00:00Z',
            ]),
    ]);

    app(TaskScheduler::class)->tick();
    $verified = $decision->fresh();
    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($verified?->labels['questions'] ?? [])->toBe([])
        ->and($verified?->labels['call']['label'])->toBe('correct');

    app(TaskScheduler::class)->tick();
    $retried = $decision->fresh();
    Artisan::call('orbit:tasks:jev-report', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['brief_coverage'];

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($retried?->merge_changes)->toBe($verified?->merge_changes)
        ->and($retried?->merge_changes_digest)->toBe($verified?->merge_changes_digest)
        ->and($retried?->merge_body_digest)->toBe($verified?->merge_body_digest)
        ->and($retried?->labels)->toBe($verified?->labels)
        ->and($report['calls'])->toBe(1)
        ->and($report['labeled_share'])->toBe(1)
        ->and($report['call_correct'])->toBe(1);
});

it('uses backoff for publication and removal retries and retries a failed manual complete', function (): void {
    [$group, $task, , , $publisher] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked the models.')]);
    $publisher->pushFailures = 1;
    $hold = 'The operator asked to hold this group.';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    $task->update(['assistance_requested' => true, 'assistance_reason' => $hold]);
    $group->update(['assistance_requested' => true, 'assistance_reason' => $hold]);
    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id, $group->id])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->assistance_reason)->toBe($hold)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe($hold);

    $remover = new class implements InstanceRemover
    {
        /** @var list<int> */
        public array $failing = [];

        /** @var list<int> */
        public array $attempts = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->attempts[] = $instance->id;
            if (in_array($instance->id, $this->failing, true)) {
                throw new RuntimeException('disk full');
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);
    $ended = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $group->project_id,
        'title' => 'Ended',
        'brief' => 'Remove the workspace.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    $workspace = Instance::query()->create([
        'project_id' => $group->project_id,
        'node_id' => $group->taskable->node_id,
        'name' => 'ended-workspace',
        'checkout_path' => '/tmp/ended-workspace',
        'status' => 'source_resolved',
    ]);
    $ended->taskable()->associate($workspace);
    $ended->save();
    $remover->failing = [$workspace->id];

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
        ->and($remover->attempts)->toBe([$workspace->id]);
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    $this->travel(TaskScheduler::retryDelaySeconds(1) - 1)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($remover->attempts)->toBe([$workspace->id]);

    $this->travel(2)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($remover->attempts)->toBe([$workspace->id, $workspace->id]);

    $this->travel(TaskScheduler::retryDelaySeconds(2) - 1)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($remover->attempts)->toBe([$workspace->id, $workspace->id]);

    $this->travel(2)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($remover->attempts)->toBe([$workspace->id, $workspace->id, $workspace->id]);

    // The third failure waits five minutes, not the four minutes a doubled delay would use.
    $this->travel(239)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($remover->attempts)->toBe([$workspace->id, $workspace->id, $workspace->id]);
    $this->travel(62)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($remover->attempts)->toHaveCount(4);

    $settling = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $group->project_id,
        'title' => 'Manual complete',
        'brief' => 'The operator completes it.',
        'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/acme/orbit/pull/77',
    ]);
    $kept = Instance::query()->create([
        'project_id' => $group->project_id,
        'node_id' => $group->taskable->node_id,
        'name' => 'manual-complete',
        'checkout_path' => '/tmp/manual-complete',
        'status' => 'source_resolved',
    ]);
    $settling->taskable()->associate($kept);
    $settling->save();
    $remover->failing[] = $kept->id;

    $completed = app(CompleteTaskGroupAction::class)->execute($settling);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($kept->id)
        ->and($completed->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'disk full');

    $other = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $group->project_id,
        'title' => 'Other cause',
        'brief' => 'Keep the question.',
        'status' => TaskGroupStatus::Cancelled,
        'assistance_requested' => true,
        'assistance_reason' => $hold,
    ]);
    $otherWorkspace = Instance::query()->create([
        'project_id' => $group->project_id,
        'node_id' => $group->taskable->node_id,
        'name' => 'other-cause',
        'checkout_path' => '/tmp/other-cause',
        'status' => 'source_resolved',
    ]);
    $other->taskable()->associate($otherWorkspace);
    $other->save();
    $remover->failing = [$workspace->id];

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(2)
        ->and(Instance::query()->find($kept->id))->toBeNull()
        ->and($settling->fresh()?->taskable_id)->toBeNull()
        ->and($settling->fresh()?->assistance_requested)->toBeFalse()
        ->and(Instance::query()->find($otherWorkspace->id))->toBeNull()
        ->and($other->fresh()?->assistance_requested)->toBeFalse()
        ->and($other->fresh()?->assistance_reason)->toBe($hold)
        ->and(Instance::query()->find($workspace->id))->not->toBeNull();
});

it('replaces its pull request assistance request with the cleanup failure when a merged group cannot complete', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.']);
    mock(InstanceRemover::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_question' => null, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
});

it('does not replace an open direction request when merged pull request cleanup fails', function (): void {
    $group = tick_settling_group();
    $question = 'Should the merged branch be kept?';
    $reason = "The reviewer is blocked: The merge removed a migration.\n\nQuestion: {$question}";
    $group->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    mock(InstanceRemover::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('changes nothing on a settling group when GitHub cannot report the pull request', function (): void {
    $group = tick_settling_group();
    $waiting = tick_appended_subtask($group);
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.';
    $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
    $notifier = tick_assistance_notifier();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([], 502),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => $reason]);
    $this->assertDatabaseHas('tasks', ['id' => $waiting->id, 'status' => 'todo']);
    expect($notifier->reasons)->toBe([]);
});

it('appends one conflict fixup and reuses its turn fetch before fast-forwarding and starting', function (): void {
    $group = tick_settling_group();
    Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Operator', 'brief' => 'Not a fixup.', 'status' => TaskStatus::Completed,
    ]);
    Task::query()->create([
        'parent_id' => $group->id, 'position' => 3, 'title' => 'Operator again', 'brief' => 'Still not a fixup.', 'status' => TaskStatus::Completed,
    ]);
    $agents = tick_running_agents();
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
    ], ['abc123' => [[
        'name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->title)->toBe('Merge origin/main')
        ->and($fixup->brief)->toBe('Merge origin/main into the task branch and resolve the conflicts. Do not rebase and do not force-push.')
        ->and($fixup->status)->toBe(TaskStatus::Running)
        ->and($fixup->deliverables)->toBe([[
            'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
            'command' => 'composer check', 'directory' => '.',
        ]])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->where('fixup_problem', 'check:Rust agent')->exists())->toBeFalse()
        ->and($agents->events)->toBe(['turn-fetch', 'fast-forward', 'spawn'])
        ->and($agents->turnFetches)->toBe(1)
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
});

it('appends one check fixup naming the failed check and its url', function (TaskGroupStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => $status]);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Custom', 'status' => 'completed', 'conclusion' => 'timed_out', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    expect($fixup->fixup_head_sha)->toBe('abc123')
        ->and($fixup->title)->toBe('Fix Custom')
        ->and($fixup->brief)->toBe('Check Custom failed: https://github.com/acme/orbit/runs/9. Do not rebase and do not force-push.')
        ->and($fixup->status)->toBe(TaskStatus::Running)
        ->and($fixup->deliverables)->toBe([[
            'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
            'command' => 'composer check', 'directory' => '.',
        ]])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
})->with([TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview]);

it('runs make check for a fixup on a non-Orbit Project and on orbit', function (string $slug): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => $slug, 'task_check' => 'make check']);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Rust agent')->sole();
    expect($fixup->brief)->toBe('Check Rust agent failed: https://github.com/acme/orbit/runs/9. Do not rebase and do not force-push.')
        ->and($fixup->deliverables)->toBe([[
            'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
            'command' => 'make check', 'directory' => '.',
        ]])
        ->and($agents->spawned)->toBe([$fixup->id]);
})->with([
    'another project' => ['shop'],
    'orbit' => ['orbit'],
]);

it('asks the reviewer to confirm a conflict fixup when the Project has no check', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'shop', 'task_check' => null]);
    tick_running_agents();
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
    ], ['abc123' => [[
        'name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->deliverables)->toBe([[
        'id' => 'fixup-review', 'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]])
        ->and(Task::query()->where('fixup_problem', 'like', 'check:%')->exists())->toBeFalse();
});

it('asks the reviewer to confirm a check fixup when the Project has no check', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'orbit', 'task_check' => null]);
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Gateway')->sole()->deliverables)->toBe([[
        'id' => 'fixup-review', 'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]]);
});

it('keeps a fixup deliverable after the Project check changes', function (): void {
    $group = tick_settling_group();
    $group->project->update(['task_check' => 'make check']);
    tick_running_agents();
    $failed = ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'];
    tick_watch_pulls([
        tick_open_pull(),
        tick_open_pull(['head' => ['sha' => 'def456']]),
    ], [
        'abc123' => [$failed],
        'def456' => [$failed],
    ]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    $recorded = [[
        'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
        'command' => 'make check', 'directory' => '.',
    ]];
    expect($fixup->deliverables)->toBe($recorded);

    $fixup->update(['status' => TaskStatus::Completed]);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $fixup->id, 'type' => 'approved', 'body' => 'Approved.',
        'author' => 'reviewer', 'review_attempt' => 1, 'commit_sha' => str_repeat('d', 40), 'posted_at' => now(),
    ]);
    $group->refresh();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->project->update(['task_check' => 'npm test']);

    app(TaskScheduler::class)->tick();

    $next = Task::query()->where('fixup_problem', 'check:Custom')->orderByDesc('position')->first();
    expect($fixup->fresh()?->deliverables)->toBe($recorded)
        ->and($next?->id)->not->toBe($fixup->id)
        ->and($next?->fixup_head_sha)->toBe('def456')
        ->and($next?->deliverables)->toBe([[
            'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
            'command' => 'npm test', 'directory' => '.',
        ]]);
});

it('appends a check fixup without a url when the run has none', function (): void {
    $group = tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Deploy', 'status' => 'completed', 'conclusion' => 'action_required',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Deploy')->sole()->brief)
        ->toBe('Check Deploy failed. Do not rebase and do not force-push.');
});

it('appends a fresh conflict fixup after operator work', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main');
    tick_appended_subtask($group)->update(['status' => TaskStatus::Completed]);
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($notifier->reasons)->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(3)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->orderByDesc('position')->first()?->status)->toBe(TaskStatus::Running)
        ->and($agents->turnFetches)->toBe(1);
});

it('asks for assistance instead of a third fixup for the same problem', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Cancelled);
    $notifier = tick_assistance_notifier();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push. Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(2);
});

it('reports only the active-window count after an operator reset', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main');
    tick_appended_subtask($group)->update(['status' => TaskStatus::Completed]);
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Failed);
    $notifier = tick_assistance_notifier();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push. Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).';

    app(TaskScheduler::class)->tick();

    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(4);
});

it('appends a conflict fixup when a different-cased problem is already at the cap', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:Main');
    tick_spent_fixup($group, 'conflict:Main');
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'conflict:main')->sole()->status)->toBe(TaskStatus::Running)
        ->and($agents->turnFetches)->toBe(1);
});

it('appends the next failed check in GitHub order after the conflict cap', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'orbit']);
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Failed);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/custom'],
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/gateway'],
    ]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    expect($fixup->deliverables)->toBe([[
        'id' => 'project-check', 'type' => 'command', 'description' => 'Run the Project task check',
        'command' => 'composer check', 'directory' => '.',
    ]])
        ->and(Task::query()->where('fixup_problem', 'check:Gateway')->exists())->toBeFalse()
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
});

it('creates no fixup for a green mergeable pull request', function (): void {
    $group = tick_settling_group();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/2',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->where('parent_id', $group->id)->where('status', 'todo')->exists())->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();
});

it('starts an appended subtask on an open settling pull request without adding a fixup', function (): void {
    $group = tick_settling_group();
    $group->update([
        'assistance_requested' => true,
        'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.',
    ]);
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($todo->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($todo->fresh()?->fixup_problem)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$todo->id]);
});

it('does not start an appended subtask while another assistance cause is set', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe('Merged pull request cleanup failed: disk full')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([])
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();
});

it('does not replace an open direction request when the pull request closes', function (): void {
    $group = tick_settling_group();
    $question = 'Which database should this use?';
    $reason = "The implementer is blocked: Need a database.\n\nQuestion: {$question}";
    $group->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    tick_appended_subtask($group);
    tick_running_agents();
    tick_watch_pulls([['merged' => false, 'state' => 'closed']]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('asks for assistance for a closed pull request and does not start an appended subtask', function (): void {
    $group = tick_settling_group();
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([['merged' => false, 'state' => 'closed']]);
    Http::fake(['https://api.github.com/repos/acme/orbit/pulls?*' => Http::response([
        ['number' => 42, 'html_url' => $group->pr_url, 'state' => 'closed', 'merged_at' => null],
    ])]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_reason)->toBe('Watched pull request ended: '.$group->pr_url.' is closed. Open subtasks: #'.$todo->id.' '.$todo->title.'.')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([]);
});

it('does not start an appended subtask when the pull request merges', function (): void {
    $group = tick_settling_group();
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    mock(InstanceRemover::class)->shouldReceive('execute')->never();
    tick_watch_pulls([['merged' => true, 'state' => 'closed']]);
    Http::fake(['https://api.github.com/repos/acme/orbit/pulls?*' => Http::response([
        ['number' => 42, 'html_url' => $group->pr_url, 'state' => 'closed', 'merged_at' => '2026-10-08T10:00:00Z'],
    ])]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe('Watched pull request ended: '.$group->pr_url.' is merged. Open subtasks: #'.$todo->id.' '.$todo->title.'.')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([]);
});

it('starts an interrupted check fixup on the next tick without appending another', function (TaskStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => TaskGroupStatus::Running]);
    $fixup = tick_spent_fixup($group, 'check:Gateway', $status);
    if ($status === TaskStatus::Running) {
        $fixup->update(['started_at' => now()]);
    }
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect($fixup->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and(Task::query()->where('fixup_problem', 'check:Gateway')->count())->toBe(1)
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
})->with([TaskStatus::Todo, TaskStatus::Running]);

it('starts an interrupted operator subtask on the next tick without appending a fixup', function (TaskStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => TaskGroupStatus::Running]);
    $todo = tick_appended_subtask($group);
    if ($status === TaskStatus::Running) {
        $todo->update(['status' => TaskStatus::Running, 'started_at' => now()]);
    }
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($todo->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($todo->fresh()?->fixup_problem)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([$todo->id]);
})->with([TaskStatus::Todo, TaskStatus::Running]);

it('cancels a stale conflict fixup before its implementer starts and returns to settling', function (): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    tick_running_agents(fetchFails: true);
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false]),
        tick_open_pull(['mergeable' => true, 'mergeable_state' => 'clean']),
    ], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null,
        'completion_summary' => 'Cancelled because the pull request is mergeable again; no conflict fixup is needed.',
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => false]);
    expect($fixup->fresh()?->settled_at)->not->toBeNull();
    expect($agents->spawned)->toBe([]);
    expect(AgentThread::query()->where('task_id', $fixup->id)->exists())->toBeFalse();
});

it('cancels an unstarted conflict fixup when its pull request closes and lets settling ask for assistance', function (): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    tick_running_agents(fetchFails: true);
    $closed = tick_open_pull(['state' => 'closed', 'mergeable' => null]);
    tick_watch_pulls([tick_open_pull(['mergeable' => false]), $closed, $closed], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null,
        'completion_summary' => 'Cancelled because the pull request closed without merging; no conflict fixup can proceed.',
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling']);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true,
        'assistance_reason' => 'The expected pull request closed without merging.', 'taskable_id' => $group->taskable_id]);
    expect($agents->spawned)->toBe([]);
});

it('cancels an unstarted conflict fixup on merge and preserves orphaned approval safeguards', function (bool $orphaned): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    $commit = $orphaned ? str_repeat('d', 40) : 'abc123';
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks()->sole()->id,
        'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $commit,
    ]);
    $remover = mock(InstanceRemover::class);
    if ($orphaned) {
        $remover->shouldNotReceive('execute');
        tick_assistance_notifier();
    } else {
        $remover->shouldReceive('execute')->once()->andReturnUsing(function (Instance $instance): InstanceRemoval {
            $instance->delete();

            return new InstanceRemoval;
        });
    }
    tick_running_agents(fetchFails: true);
    $merged = tick_open_pull(['merged' => true, 'state' => 'closed', 'mergeable' => null]);
    tick_watch_pulls([tick_open_pull(['mergeable' => false]), $merged, $merged], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null,
        'completion_summary' => 'Cancelled because the pull request merged; no conflict fixup is needed.',
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling']);

    app(TaskScheduler::class)->tick();

    if ($orphaned) {
        $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'taskable_id' => $group->taskable_id]);
        expect($group->fresh()?->assistance_reason)->toStartWith(TaskScheduler::OrphanedCommitPrefix.'Commit '.$commit);
    } else {
        $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'taskable_id' => null]);
    }
    expect($agents->spawned)->toBe([]);
})->with(['merged approval' => false, 'approval missed merge' => true]);

it('retains an orphaned approval after interruption between settling and recording its hold', function (bool $otherAssistance, bool $direction): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    $instanceId = $group->taskable_id;
    $commit = str_repeat('d', 40);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks()->sole()->id,
        'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $commit,
    ]);
    $removals = 0;
    mock(InstanceRemover::class)->shouldReceive('execute')->andReturnUsing(function (Instance $instance) use (&$removals): InstanceRemoval {
        $removals++;
        $instance->delete();

        return new InstanceRemoval;
    });
    mock(TaskSettleMetricsCollector::class)->shouldNotReceive('collect');
    $notifier = tick_assistance_notifier();
    tick_running_agents(fetchFails: true);
    $merged = tick_open_pull(['merged' => true, 'state' => 'closed', 'mergeable' => null]);
    tick_watch_pulls([tick_open_pull(['mergeable' => false]), $merged, $merged, $merged], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();
    // Abort the first hold write, after the preceding settling transaction has committed.
    DB::statement("CREATE TRIGGER interrupt_orphaned_approval_hold BEFORE UPDATE ON tasks WHEN NEW.id = {$group->id} AND NEW.assistance_reason LIKE 'An approved commit is not on the pull request:%' BEGIN SELECT RAISE(ABORT, 'Gateway interrupted before orphaned hold'); END");
    try {
        expect(fn () => app(TaskScheduler::class)->tick())->toThrow(QueryException::class, 'Gateway interrupted before orphaned hold');
    } finally {
        DB::statement('DROP TRIGGER interrupt_orphaned_approval_hold');
    }
    $this->assertDatabaseHas('tasks', ['id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => false, 'taskable_id' => $instanceId]);
    if ($otherAssistance) {
        TaskAssistance::apply($group, $direction ? AssistanceKind::Direction : AssistanceKind::Failure, $direction ? 'Which branch should retain the approval?' : null, 'Another assistance cause.');
    }

    // A fresh Gateway tick must revalidate the approval, not trust the absent hold.
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'taskable_id' => $instanceId]);
    $this->assertDatabaseHas('instances', ['id' => $instanceId]);
    expect($removals)->toBe(0);
    if ($direction) {
        expect($group->fresh()?->assistance_reason)->toBe('Another assistance cause.')
            ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
            ->and($group->fresh()?->assistance_question)->toBe('Which branch should retain the approval?');
        expect($notifier->reasons)->toBe([]);
    } else {
        expect($group->fresh()?->assistance_reason)->toStartWith(TaskScheduler::OrphanedCommitPrefix.'Commit '.$commit);
        expect($notifier->reasons)->toBe([$group->fresh()?->assistance_reason]);
    }
    expect($agents->spawned)->toBe([]);
})->with([
    'hold missing after interruption' => [false, false],
    'another failure after interruption' => [true, false],
    'direction request after interruption' => [true, true],
]);

it('waits without cancelling or spawning a conflict fixup when mergeability is unknown', function (): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    tick_running_agents(fetchFails: true);
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false]),
        tick_open_pull(['mergeable' => null, 'mergeable_state' => 'unknown']),
        tick_open_pull(),
    ], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $fixup->id, 'status' => 'running', 'completion_summary' => null, 'implementer_agent_thread_id' => null]);
    expect($agents->spawned)->toBe([]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $fixup->id, 'status' => 'cancelled']);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling']);
    expect($agents->spawned)->toBe([]);
});

it('leaves a conflict fixup todo when the base fetch fails', function (): void {
    $group = tick_settling_group();
    $agents = tick_running_agents(fetchFails: true);
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->status)->toBe(TaskStatus::Todo)
        ->and($fixup->communication_failures)->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($agents->events)->toBe(['turn-fetch'])
        ->and($agents->spawned)->toBe([]);
});

it('retries a failed conflict fixup fetch on the backoff and asks for assistance on the fifth failure', function (): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents(fetchFails: true);
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($agents->turnFetches)->toBe(1);

    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->status)->toBe(TaskStatus::Todo)
        ->and($fixup->communication_failures)->toBe(5)
        ->and($fixup->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($fixup->assistance_question)->toBeNull()
        ->and($fixup->assistance_reason)->toBe('The base branch could not be fetched.')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_reason)->toBe('The base branch could not be fetched.')
        ->and($notifier->reasons)->toBe(['The base branch could not be fetched.']);

    app(TaskScheduler::class)->tick();

    expect($fixup->fresh()?->communication_failures)->toBe(5)
        ->and($agents->turnFetches)->toBe(5);
});

it('appends no fixup while the head is the one the last fixup committed from', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'check:Custom', headSha: 'abc123', commit: str_repeat('d', 40));
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->reasons)->toBe([])
        ->and($agents->spawned)->toBe([]);
});

it('asks for assistance instead of a second fixup when the last fixup changed nothing', function (?string $commit, TaskStatus $status): void {
    $group = tick_settling_group();
    $spent = tick_spent_fixup($group, 'check:Custom', $status, headSha: 'abc123', commit: $commit);
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(), tick_open_pull()], ['abc123' => [[
        'name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);
    $reason = 'The pull request needs attention: Check Custom failed: https://github.com/acme/orbit/runs/9. Fixup subtask #'.$spent->id.' changed nothing, so Orbit does not try again on the same result.';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and($notifier->reasons)->toBe([$reason])
        ->and($agents->spawned)->toBe([]);
})->with([
    'same commit' => ['abc123', TaskStatus::Completed],
    'no approval' => [null, TaskStatus::Cancelled],
]);

it('waits for the checks on a new head to complete before the next fixup', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'check:Custom', headSha: 'abc123', commit: str_repeat('d', 40));
    $agents = tick_running_agents();
    $failed = ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/10'];
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(tick_open_pull(['head' => ['sha' => 'def456']])),
        'https://api.github.com/repos/acme/orbit/commits/def456/check-runs*' => Http::sequence()
            ->push(['total_count' => 2, 'check_runs' => [$failed, ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/orbit/runs/11']]])
            ->push(['total_count' => 2, 'check_runs' => [$failed, ['name' => 'Web', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/11']]]),
    ]);

    $notifier = tick_assistance_notifier();

    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Custom failed: https://github.com/acme/orbit/runs/10.';
    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and($notifier->reasons)->toBe([$reason]);

    $this->travel(61)->seconds();
    app(TaskScheduler::class)->tick();

    $next = Task::query()->where('fixup_problem', 'check:Custom')->orderByDesc('position')->first();
    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(2)
        ->and($next?->fixup_head_sha)->toBe('def456')
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($agents->spawned)->toBe([$next?->id]);
});

it('appends one fixup for a failed check and ignores the Required checks rollup it explains', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'orbit']);
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->whereNotNull('fixup_problem')->pluck('fixup_problem')->all())->toBe(['check:Gateway']);
});

it('waits with backoff on cancelled checks and asks for assistance only when they persist', function (string $conclusion): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    $pulls = array_fill(0, 12, tick_open_pull());
    tick_watch_pulls($pulls, ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => $conclusion, 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect($notifier->reasons)->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();

    $this->travel(1800)->seconds();
    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Gateway failed: https://github.com/acme/orbit/runs/1. Those checks were cancelled or could not start, and did not recover. Re-run them.';
    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
})->with(['cancelled', 'startup_failure']);

it('fixes a genuine failure and does not count a cancelled check as a problem', function (): void {
    $group = tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'cancelled', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->whereNotNull('fixup_problem')->pluck('fixup_problem')->all())->toBe(['check:Custom']);
});

it('reports a genuine failure while another check is pending and appends no check fixup', function (): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(), tick_open_pull()], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(30)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11', 'id' => 11],
    ]]);
    $reason = 'The pull request needs attention: Check Custom failed: https://github.com/acme/orbit/runs/9.';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
});

it('appends a conflict fixup while a check is pending', function (): void {
    $group = tick_settling_group();
    $agents = tick_running_agents();
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
    ], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(5)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11'],
    ]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'conflict:main')->sole()->status)->toBe(TaskStatus::Running)
        ->and(Task::query()->where('fixup_problem', 'like', 'check:%')->exists())->toBeFalse()
        ->and($agents->turnFetches)->toBe(1)
        ->and($agents->spawned)->not->toBe([]);
});

it('fixes a genuine failure beside a check pending for more than 60 minutes', function (): void {
    $group = tick_settling_group();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(61)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11'],
    ]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    expect($fixup->status)->toBe(TaskStatus::Running)
        ->and(Task::query()->where('fixup_problem', 'check:Web')->exists())->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($agents->spawned)->toBe([$fixup->id]);
});

it('uses the infrastructure path when a check is pending for more than 60 minutes', function (): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls(array_fill(0, 12, tick_open_pull()), ['abc123' => [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null,
        'started_at' => now()->subMinutes(61)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11',
    ]]]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect($notifier->reasons)->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();

    $this->travel(1800)->seconds();
    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Web is still pending: https://github.com/acme/orbit/runs/11. Those checks were cancelled or could not start, and did not recover. Re-run them.';
    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
});

it('ages a pending check with no started_at from the first read and does not move that time', function (): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    tick_running_agents();
    tick_watch_pulls(array_fill(0, 12, tick_open_pull()), ['abc123' => [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/orbit/runs/11',
    ]]]);

    app(TaskScheduler::class)->tick();
    $this->travel(50)->minutes();
    app(TaskScheduler::class)->tick();
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();

    $this->travel(11)->minutes();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }
    expect($notifier->reasons)->toBe([]);

    $this->travel(1800)->seconds();
    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Web is still pending: https://github.com/acme/orbit/runs/11. Those checks were cancelled or could not start, and did not recover. Re-run them.';
    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();
});

it('does not start infrastructure backoff while a check is still pending', function (): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls(array_fill(0, 12, tick_open_pull()), ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'cancelled', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(10)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11'],
    ]]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600, 1800] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect($notifier->reasons)->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
});

it('asks for assistance once the group has three Gateway fixups', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'check:Custom');
    tick_spent_fixup($group, 'check:Lint');
    $notifier = tick_assistance_notifier();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Deploy', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/3'],
    ]]);

    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Deploy failed: https://github.com/acme/orbit/runs/3. Orbit already appended 3 fixups to this group.';
    expect($notifier->reasons)->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and(Task::query()->whereNotNull('fixup_problem')->count())->toBe(3)
        ->and($agents->spawned)->toBe([]);
});

it('fast-forwards the workspace after the turn fetch and backs off until assistance on the fifth failure', function (): void {
    $group = tick_settling_group();
    $waiting = tick_appended_subtask($group);
    $agents = tick_running_agents(fastForwardFails: true);
    $notifier = tick_assistance_notifier();
    tick_watch_pulls(array_fill(0, 6, tick_open_pull()), ['abc123' => []]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($agents->fastForwards)->toBe(1)
        ->and($agents->turnFetches)->toBe(1)
        ->and($agents->events)->toBe(['turn-fetch', 'fast-forward'])
        ->and($agents->missingRefOk)->toBeFalse()
        ->and($waiting->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($waiting->fresh()?->communication_failures)->toBe(1)
        ->and($agents->spawned)->toBe([]);

    $this->travel(60)->seconds();
    app(TaskScheduler::class)->tick();

    expect($agents->fastForwards)->toBe(2)
        ->and($agents->turnFetches)->toBe(2)
        ->and($waiting->fresh()?->communication_failures)->toBe(2);

    foreach ([120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect($agents->fastForwards)->toBe(5)
        ->and($agents->turnFetches)->toBe(5)
        ->and($waiting->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($waiting->fresh()?->communication_failures)->toBe(5)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reasons)->toBe(['The task branch could not be fetched.']);

    app(TaskScheduler::class)->tick();

    expect($agents->fastForwards)->toBe(5)
        ->and($agents->turnFetches)->toBe(5)
        ->and($agents->spawned)->toBe([]);
});

it('reports a throwing brief coverage labeler and continues the tick', function (): void {
    $group = tick_settling_group();
    Exceptions::fake();
    app()->instance(BriefCoverageLabeler::class, new class implements BriefCoverageLabeler
    {
        public function label(Task $group, TaskPullRequestHealth $health): void
        {
            throw new RuntimeException('labeling failed');
        }
    });
    tick_watch_pulls([['merged' => true, 'state' => 'closed', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']]]);

    expect(app(TaskScheduler::class)->tick())->toBe([]);

    Exceptions::assertReported(RuntimeException::class);
});

it('keeps a merged group settling when an approved commit missed the merge', function (): void {
    $group = tick_settling_group();
    $reason = TaskScheduler::OrphanedCommitPrefix.'Commit '.str_repeat('d', 40).' reached task-'.$group->id.' after the merge.';
    $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
    tick_watch_pulls([['merged' => true, 'state' => 'closed', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']]]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_reason' => $reason]);
});

it('returns no decisions when the tasks extension is disabled', function (): void {
    tick_group();

    expect(app(TaskScheduler::class)->tick())->toBe([]);
});

it('drains a pending approval chosen by the faked Choice', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId !== 'implementer-thread') {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }

            return [
                'thread' => [
                    'session' => ['status' => 'waiting'],
                    'pendingApprovals' => [['requestId' => 'approval-tick']],
                ],
            ];
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('waiting for input')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('escalates to Coder when a drain dispatch fails', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            throw new AgentDriverException('Agent approval respond failed.');
        }
    };
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->reason = $decision->reason;
        }

        public function assistance(Task $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId !== 'implementer-thread') {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }

            return [
                'thread' => [
                    'session' => ['status' => 'waiting'],
                    'pendingApprovals' => [['requestId' => 'approval-tick']],
                ],
            ];
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);

    app(TaskScheduler::class)->tick();

    expect($group->tasks()->first()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->reason)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('advances the current subtask when Jev marks it done', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $spawner = new class implements AgentSpawner
    {
        public int $spawned = 0;

        public function spawnReviewer(Task $task): ?int
        {
            $this->spawned++;

            return test_agent_thread($task->parent, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    app()->instance(AgentSpawner::class, $spawner);

    $started = app(TaskScheduler::class)->tick();
    $decisions = app(TaskScheduler::class)->tick();

    expect($started)->toBe([])
        ->and($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($group->fresh()?->tasks->first()?->status)->toBe(TaskStatus::Reviewing)
        ->and($spawner->spawned)->toBe(1);
});

it('hands off with the Project task check, and runs no command when the Project has none', function (?string $taskCheck): void {
    $group = tick_group();
    $group->project->update(['task_check' => $taskCheck]);
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    tick_workspace();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(TaskCheckRunner::class)->commands)->toBe([$taskCheck]);
})->with([
    'no task check' => [null],
    'custom task check' => ['vp run check'],
]);

it('records an unreachable workspace as a communication failure without aborting the tick', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $receipts = mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('hasLegacyTurn')->andReturn(false);
    $receipts->shouldReceive('read')->andThrow(new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.'));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
    Classification::assertNothingClassified();
});

it('records a legacy turn read failure without skipping the other group', function (): void {
    $running = tick_group();
    $implementer = $running->tasks->sole();
    $project = Project::query()->create([
        'name' => 'tick-review-legacy', 'slug' => 'tick-review-legacy',
        'repository_url' => 'git@example.test:tick-review-legacy.git', 'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'tick-review-legacy-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.213', 'wireguard_ip' => '10.44.0.213',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-22',
        'checkout_path' => '/srv/orbit/apps/tick-review-legacy/task-22', 'branch' => 'task-22', 'status' => 'source_resolved',
    ]);
    $reviewing = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Tick review', 'brief' => 'Review the records.', 'status' => TaskGroupStatus::Reviewing,
    ]);
    $reviewing->taskable()->associate($instance);
    $reviewing->save();
    $reviewer = Task::query()->create([
        'parent_id' => $reviewing->id, 'position' => 1, 'title' => 'Review', 'brief' => 'Review the records.',
        'status' => TaskStatus::Reviewing, 'started_at' => now(),
        'review_notified_attempt' => 1, 'review_notified_turn_id' => 'handoff-turn',
    ]);
    test_link_agent_threads($reviewing);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'done'],
                'latestTurn' => ['id' => 'review-turn', 'state' => 'completed'],
            ]];
        }
    });
    $receipts = mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('hasLegacyTurn')->andThrow(new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.'));

    app(TaskScheduler::class)->tick();

    expect($implementer->fresh()?->communication_failures)->toBe(1)
        ->and($implementer->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($reviewer->fresh()?->communication_failures)->toBe(1)
        ->and($reviewer->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($running->id)->toBeLessThan($reviewing->id);
});

it('stores a ready_for_review receipt, removes it, and hands off to the reviewer', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $contents = FakeTaskTurnReceipts::contents('ready_for_review', 'Added the models.');
    $receipts = new FakeTaskTurnReceipts([$contents]);
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $comment = $task->comments()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($comment->getRawOriginal('type'))->toBe('ready_for_review')
        ->and($comment->body)->toBe('Added the models.')
        ->and($comment->author)->toBe('implementer')
        ->and($comment->agent_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($comment->completion_attempt)->toBe($task->fresh()?->completion_attempt)
        ->and($comment->receipt_hash)->toBe(hash('sha256', $contents))
        ->and($receipts->cleared)->toBe([hash('sha256', $contents)]);
    Classification::assertNothingClassified();
});

it('stores a receipt that was read again after a crash only once', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $contents = FakeTaskTurnReceipts::contents('ready_for_review');
    $task->comments()->create([
        'task_group_id' => $group->id, 'type' => 'ready_for_review', 'body' => 'Done.', 'author' => 'implementer',
        'completion_attempt' => $task->completion_attempt, 'receipt_hash' => hash('sha256', $contents), 'posted_at' => now(),
    ]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([$contents]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('starts a consult when the implementer blocks before any reviewer exists', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Installing the extension needs sudo, and sudo was denied.', 'May the Node user run sudo apt-get install php8.5-intl?')]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done']);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->sole();
    $opening = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === $reviewer->external_id);
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->consult_comment_id)->toBe($task->comments()->sole()->id)
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('blocked')
        ->and($receipts->cleared)->toHaveCount(1)
        ->and($receipts->modes)->toContain('consult')
        ->and($question->asked_by)->toBe(QuestionAsker::Implementer)
        ->and($question->status)->toBe(QuestionStatus::Open)
        ->and($question->consult)->toBeTrue()
        ->and($question->cause)->toBeNull()
        ->and($question->question)->toBe('May the Node user run sudo apt-get install php8.5-intl?')
        ->and($question->escalated_at)->toBeNull()
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(0)
        ->and($group->fresh()?->questions)->toBe(1)
        ->and($group->fresh()?->escalations)->toBe(0)
        ->and($opening['message']['text'] ?? null)->toContain('May the Node user run sudo apt-get install php8.5-intl?')
        ->and($opening['message']['text'] ?? null)->toContain('--outcome=answered');
});

it('does not send a second consult when an accepted send lost its response', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(true);
    $attempt = $task->completion_attempt;
    $answer = 'Yes. The contract allows the intl extension.';

    app(TaskScheduler::class)->tick();

    $starts = tick_reviewer_starts($dispatcher);
    expect($starts)->toHaveCount(1)
        ->and($task->fresh()?->consult_comment_id)->not->toBeNull()
        ->and($task->fresh()?->direction_answer_key)->toBe($starts[0]['commandId'])
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(2);

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(2)
        ->and($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answer)->toBe($answer)
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($question->consult)->toBeTrue()
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and(TaskQuestion::query()->count())->toBe(1);

    app(TaskScheduler::class)->tick();

    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer)
        ->and(TaskQuestion::query()->count())->toBe(1);
});

it('keeps the consult answer when the marker update fails after the send', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(false);
    $attempt = $task->completion_attempt;
    $answer = 'Yes. The contract allows the intl extension.';
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject): void {
        if (! $inject || ! str_starts_with(strtolower(ltrim($sql)), 'update') || ! str_contains($sql, 'reviewer_agent_thread_id')) {
            return;
        }
        $inject = false;

        throw new RuntimeException('injected consult marker failure');
    });

    expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class, 'injected consult marker failure');

    $starts = tick_reviewer_starts($dispatcher);
    expect($starts)->toHaveCount(1)
        ->and($task->fresh()?->consult_comment_id)->not->toBeNull()
        ->and($task->fresh()?->direction_answer_key)->toBe($starts[0]['commandId'])
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and(TaskQuestion::query()->sole()->question)->toBe('May I install php8.5-intl?');

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(2)
        ->and($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answer)->toBe($answer)
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->consult_comment_id)->toBeNull();

    app(TaskScheduler::class)->tick();

    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->id)->toBe($question->id)
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer);
});

it('reuses the fresh reviewer when saving its conversation id fails after the opening turn', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(false);
    $answer = 'Yes. The contract allows the intl extension.';
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject, $dispatcher): void {
        if (! $inject || tick_reviewer_starts($dispatcher) === []) {
            return;
        }
        $statement = strtolower(ltrim($sql));
        if (str_starts_with($statement, 'update') && str_contains($sql, 'external_id')) {
            $inject = false;

            throw new RuntimeException('injected external id failure');
        }
    });

    app(TaskScheduler::class)->tick();

    expect($inject)->toBeFalse();

    $created = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'create');
    $externalId = is_array($created) ? (string) ($created['threadId'] ?? '') : '';
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    expect($externalId)->not->toBe('')
        ->and($reviewer->external_id)->toBe($externalId)
        ->and($reviewer->external_id)->not->toStartWith('pending:')
        ->and(collect($dispatcher->commands)->where('type', 'create'))->toHaveCount(1)
        ->and(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);

    app(TaskScheduler::class)->tick();

    expect(collect($dispatcher->commands)->where('type', 'create'))->toHaveCount(1)
        ->and(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and($reviewer->fresh()?->external_id)->toBe($externalId)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer)
        ->and($task->fresh()?->completion_attempt)->toBe($task->completion_attempt);
});

it('reuses the Pi reviewer when both opening send responses are lost', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['reviewer_agent_driver' => 'pi', 'reviewer_model' => 'gpt-5.6-luna']);
    $group->taskable->node->update([
        'settings' => ['pi' => ['token' => 'pi-node-token-with-more-than-32-characters', 'url' => 'http://10.44.0.212:3774']],
    ]);
    $answer = 'Yes. The contract allows the intl extension.';
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', $answer, cause: 'missed_contract'),
        null,
    ]);
    $pi = (object) ['sessions' => [], 'keys' => [], 'implementerKey' => ''];
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([app(PiDriver::class)]));
    Http::fake(function (Request $request) use ($pi): mixed {
        if ($request->method() === 'POST' && str_ends_with(rtrim($request->url(), '/'), '/sessions')) {
            $pi->sessions[] = (string) $request['id'];

            return Http::response(['id' => $request['id']], 201);
        }
        if ($request->method() === 'POST' && str_contains($request->url(), '/messages')) {
            if (str_contains($request->url(), '/implementer-thread/')) {
                $pi->implementerKey = (string) $request['key'];

                return Http::response(['key' => $request['key'], 'status' => 'accepted'], 202);
            }
            $pi->keys[] = (string) $request['key'];

            throw new ConnectionException('response lost');
        }
        if ($request->method() === 'GET') {
            $sessionId = basename(rtrim($request->url(), '/'));

            return Http::response([
                'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 2,
                'session' => ['id' => $sessionId],
                'state' => 'done',
                'turnId' => $sessionId === 'implementer-thread' ? $pi->implementerKey : ($pi->keys[0] ?? ''),
                'entries' => [],
            ]);
        }

        return Http::response([], 404);
    });

    app(TaskScheduler::class)->tick();

    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    expect($pi->sessions)->toHaveCount(1)
        ->and($reviewer->external_id)->toBe($pi->sessions[0])
        ->and($reviewer->driver)->toBe('pi')
        ->and(array_unique($pi->keys))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);

    app(TaskScheduler::class)->tick();

    expect($pi->sessions)->toHaveCount(1)
        ->and(array_unique($pi->keys))->toHaveCount(1)
        ->and($reviewer->fresh()?->external_id)->toBe($pi->sessions[0])
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->consult_comment_id)->toBeNull();
});

it('reminds a consult reviewer that stops without a receipt, then asks for a failure', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        null,
        null,
    ]);

    app(TaskScheduler::class)->tick();
    $reviewerId = $group->fresh()?->reviewer_agent_thread_id;

    app(TaskScheduler::class)->tick();

    $reminder = tick_reviewer_starts($dispatcher)[1]['message']['text'] ?? null;
    expect($reminder)->toBe(TaskTurnFetchNotice::Failed."\n\nOrbit could not confirm the review is complete. No turn receipt was found. ".TaskTurnInstructions::consult(is_int($reviewerId) ? $reviewerId : null))
        ->and($reminder)->not->toContain('--outcome=approved')
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(3);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.');
});

it('asks for a failure when the consult reviewer thread fails', function (): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
    ]);

    app(TaskScheduler::class)->tick();
    $state->reviewer = 'error';
    $state->reviewerError = 'The model rejected the turn.';

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_reason)->toBe('The reviewer thread failed.')
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);
});

it('resumes a consult reviewer after a server restart', function (): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
    ]);

    app(TaskScheduler::class)->tick();
    $state->reviewer = 'error';
    $state->reviewerError = TaskScheduler::PiServerRestartError;

    app(TaskScheduler::class)->tick();

    $resume = tick_reviewer_starts($dispatcher)[1] ?? null;
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('pending')
        ->and($resume['message']['text'] ?? null)->toBe(TaskTurnFetchNotice::Failed."\n\n".TaskScheduler::PiServerRestartContinue)
        ->and($resume['commandId'] ?? null)->toBe($task->fresh()?->pi_restart_key)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);
});

it('uses observation grace for an unavailable consult reviewer, then asks for a failure', function (): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
    ]);

    app(TaskScheduler::class)->tick();
    $state->unavailable = true;

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->agent_unavailable_since)->not->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    $this->travel(121)->seconds();
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_reason)->toBe('Agent observation unavailable beyond the grace period.')
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);
});

it('recovers a stopped receiptless topology resume after lost Pi responses or a post-send marker failure', function (bool $writeFails, bool $superseded): void {
    [$group, $task, , $dispatcher] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'Need Nodes.', 'Can you request a topology?'),
        FakeTaskTurnReceipts::contents('topology_requested', 'Need Nodes to answer.'),
        null, // Accepted resume stops without a usable receipt.
        null, // The normal missing-receipt reminder must still run.
        FakeTaskTurnReceipts::contents('answered', 'Continue with the contract.', cause: 'environment'),
    ]);
    $topology = new FakeTaskWorkspaceTopology;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    app(TaskScheduler::class)->tick();
    $consultId = $task->fresh()->consult_comment_id;
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    $reviewer->update(['driver' => 'pi']);
    $group->taskable->node->update([
        'settings' => ['pi' => ['token' => 'pi-node-token-with-more-than-32-characters', 'url' => 'http://10.44.0.212:3774']],
    ]);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([app(PiDriver::class)]));
    $source = 'original-topology-request-turn';
    $pi = (object) ['turnId' => $source, 'keys' => [], 'messages' => [], 'implementerKeys' => [], 'implementerTurnId' => ''];
    Http::fake(function (Request $request) use ($pi, $writeFails): mixed {
        if ($request->method() === 'POST' && str_contains($request->url(), '/messages')) {
            $key = (string) $request['key'];
            if (str_contains($request->url(), '/implementer-thread/')) {
                $pi->implementerKeys[] = $key;
                $pi->implementerTurnId = $key;

                return Http::response(['key' => $key, 'status' => 'accepted'], 202);
            }
            $pi->keys[] = $key;
            $pi->messages[] = (string) $request['text'];
            $pi->turnId = $key; // Pi accepts even when both replies are lost.
            if (! $writeFails && str_starts_with($key, 'topology-request-')) {
                throw new ConnectionException('accepted response lost');
            }

            return Http::response(['key' => $key, 'status' => 'accepted'], 202);
        }
        if ($request->method() === 'GET') {
            return Http::response([
                'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 2,
                'session' => ['id' => basename(rtrim($request->url(), '/'))],
                'state' => 'done',
                'turnId' => str_ends_with($request->url(), '/implementer-thread') ? $pi->implementerTurnId : $pi->turnId,
                'entries' => [],
            ]);
        }

        return Http::response([], 404);
    });
    $inject = $writeFails;
    DB::beforeExecuting(function (string $sql) use (&$inject, $pi): void {
        if ($inject && $pi->keys !== [] && str_starts_with(strtolower(ltrim($sql)), 'update') && str_contains($sql, 'review_handled_comment_id')) {
            $inject = false;

            throw new RuntimeException('injected post-send topology marker failure');
        }
    });
    if ($writeFails) {
        expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class, 'injected post-send topology marker failure');
    } else {
        app(TaskScheduler::class)->tick();
    }
    $request = $task->comments()->where('type', 'topology_requested')->sole();
    expect($request->topology_resume)->toBe(['source_turn_id' => $source])
        ->and($pi->keys)->toHaveCount($writeFails ? 1 : 2)
        ->and(array_unique($pi->keys))->toBe(['topology-request-'.$request->id]);
    if ($superseded) {
        $pi->turnId = 'later-stopped-turn';
    }

    app(TaskScheduler::class)->tick(); // Reconcile, without resending or rebasing onto the resumed turn.
    expect($task->fresh()->review_notified_turn_id)->toBe($source)
        ->and($pi->keys)->toHaveCount($writeFails ? 1 : 2)
        ->and($task->fresh()->consult_comment_id)->toBe($consultId)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and($task->fresh()->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();
    expect(end($pi->messages))->toContain('No turn receipt was found.')
        ->and(end($pi->messages))->toContain('--outcome=answered')
        ->and($task->fresh()->consult_comment_id)->toBe($consultId)
        ->and($topology->calls)->toHaveCount(1);

    app(TaskScheduler::class)->tick();
    expect(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()->consult_comment_id)->toBeNull()
        ->and($group->fresh()->reviewer_agent_thread_id)->toBe($reviewer->id)
        ->and($pi->implementerKeys)->toHaveCount(1);
})->with([
    'lost replies, accepted turn' => [false, false],
    'lost replies, superseding turn' => [false, true],
    'post-send write failure, accepted turn' => [true, false],
    'post-send write failure, superseding turn' => [true, true],
]);

it('preserves the implementer source turn after an ambiguous refusal resume and recovers through a consult', function (bool $writeFails): void {
    [$group, $task, , $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('topology_requested', 'Need Nodes.'),
        null,
        null,
        FakeTaskTurnReceipts::contents('blocked', 'Need Nodes.', 'Can the reviewer request a topology?'),
    ]);
    $source = 'implementer-resource-request';
    $state->turnId = $source;
    $state->loseImplementerResponse = ! $writeFails;
    $topology = new FakeTaskWorkspaceTopology;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    $inject = $writeFails;
    DB::beforeExecuting(function (string $sql) use (&$inject, $dispatcher): void {
        if ($inject && $dispatcher->commands !== [] && str_starts_with(strtolower(ltrim($sql)), 'update') && str_contains($sql, 'completion_handoff_comment_id')) {
            $inject = false;

            throw new RuntimeException('injected refusal marker failure');
        }
    });
    if ($writeFails) {
        expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class, 'injected refusal marker failure');
    } else {
        app(TaskScheduler::class)->tick();
    }
    $request = $task->comments()->where('type', 'topology_requested')->sole();
    expect($request->topology_resume)->toBe(['source_turn_id' => $source]);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->completion_handoff_turn_id)->toBe($source)
        ->and($dispatcher->commands)->toHaveCount(1);
    app(TaskScheduler::class)->tick();
    expect(json_encode($dispatcher->commands[1]))->toContain('No turn receipt was found.')
        ->and($topology->calls)->toBe([]);
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->consult_comment_id)->not->toBeNull()
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and($task->fresh()->assistance_requested)->toBeFalse();
})->with([false, true]);

it('keeps a consult open and the implementer paused through a topology request, including acquisition failure', function (bool $fails): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'Discovery needs a topology.', 'Can you request one?'),
        FakeTaskTurnReceipts::contents('topology_requested', 'Need Nodes to answer.'),
        FakeTaskTurnReceipts::contents('answered', 'Continue with the contract.', cause: 'environment'),
    ]);
    $topology = new FakeTaskWorkspaceTopology;
    $topology->fails = $fails;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    app(TaskScheduler::class)->tick();
    $state->reviewerTurnId = 'topology-request-turn';
    $consultId = $task->fresh()->consult_comment_id;
    $reviewerId = $group->fresh()->reviewer_agent_thread_id;
    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and(TaskQuestion::query()->sole()->cause)->toBeNull()
        ->and($task->fresh()->consult_comment_id)->toBe($consultId)
        ->and($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->reviewer_agent_thread_id)->toBe($reviewerId)
        ->and(end($receipts->modes))->toBe('consult')
        ->and(json_encode($dispatcher->commands))->toContain($fails ? 'acquisition failed:' : 'is ready.');
    $implementerSends = collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread');
    expect($implementerSends)->toHaveCount(0);

    app(TaskScheduler::class)->tick();
    expect(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()->consult_comment_id)->toBeNull();
})->with([false, true]);

it('refuses a handwritten implementer topology request and asks it to consult the reviewer', function (): void {
    [$group, $task, , $dispatcher] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('topology_requested', 'Need Nodes.'),
    ]);
    $topology = new FakeTaskWorkspaceTopology;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    app(TaskScheduler::class)->tick();

    expect($topology->calls)->toBe([])
        ->and($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()->assistance_requested)->toBeFalse()
        ->and(TaskQuestion::query()->count())->toBe(0)
        ->and(json_encode($dispatcher->commands))->toContain('Topology request refused. Ask the reviewer through a blocked consult');
});

it('answers a consult in the same attempt and records the reviewer cause', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', 'Yes. The contract allows the intl extension.', cause: 'missed_contract'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'done', 'reviewer' => 'running'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();
    $reviewerId = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole()->id;

    app(TaskScheduler::class)->tick();

    expect($receipts->reads())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and($task->fresh()?->consult_comment_id)->not->toBeNull();

    $state->reviewer = 'done';
    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    $delivered = collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread');
    expect($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answered_by)->toBe(QuestionAsker::Reviewer)
        ->and($question->answer)->toBe('Yes. The contract allows the intl extension.')
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($question->consult)->toBeTrue()
        ->and($question->escalated_at)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(0)
        ->and(AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole()->id)->toBe($reviewerId)
        ->and($delivered)->toHaveCount(1)
        ->and($delivered->first()['message']['text'] ?? null)->toContain('Yes. The contract allows the intl extension.');
});

it('escalates the same consult when the reviewer blocks', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The brief does not name a database.', 'Which database should this subtask use?'),
        FakeTaskTurnReceipts::contents('blocked', 'The contract does not choose a database.', 'Which database should the operator choose?', cause: 'contract_gap'),
    ]);
    tick_relay_runtime($receipts, tick_dispatcher(), (object) ['implementer' => 'done']);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect($question->status)->toBe(QuestionStatus::Escalated)
        ->and($question->asked_by)->toBe(QuestionAsker::Implementer)
        ->and($question->consult)->toBeTrue()
        ->and($question->question)->toBe('Which database should the operator choose?')
        ->and($question->cause)->toBe(QuestionCause::ContractGap)
        ->and($question->escalated_at)->not->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe('Which database should the operator choose?')
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(1)
        ->and(TaskQuestion::query()->where('consult', true)->count())->toBe(1);
});

it('asks for direction on the third block and a later relay does not count toward that limit', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'First block.', 'Which mirror?'),
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'missed_contract'),
        FakeTaskTurnReceipts::contents('blocked', 'Second block.', 'Which region?'),
        FakeTaskTurnReceipts::contents('answered', 'Use the region in the brief.', cause: 'brief_unclear'),
        FakeTaskTurnReceipts::contents('blocked', 'Third block.', 'May I provision a database?'),
        FakeTaskTurnReceipts::contents('answered', 'Provision the shared database.', cause: 'environment'),
        FakeTaskTurnReceipts::contents('blocked', 'Fourth block.', 'May I buy more disk?'),
    ]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done']);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $third = TaskQuestion::query()->where('consult', false)->sole();
    expect(TaskQuestion::query()->where('consult', true)->count())->toBe(2)
        ->and($third->status)->toBe(QuestionStatus::Escalated)
        ->and($third->asked_by)->toBe(QuestionAsker::Implementer)
        ->and($third->question)->toBe('May I provision a database?')
        ->and($third->cause)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe('May I provision a database?')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the public mirror.')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the region in the brief.')
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->consult_comment_id)->toBeNull();

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Provision the shared database.', 'author' => 'operator',
    ]);
    expect($task->fresh()?->direction_relay_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($receipts->modes)->toContain('relay');

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->where('consult', true)->count())->toBe(2)
        ->and($third->fresh()?->status)->toBe(QuestionStatus::Answered)
        ->and($third->fresh()?->answered_by)->toBe(QuestionAsker::Operator)
        ->and($third->fresh()?->answer)->toBe('Provision the shared database.')
        ->and($third->fresh()?->cause)->toBe(QuestionCause::Environment)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->where('consult', true)->count())->toBe(2)
        ->and(TaskQuestion::query()->where('consult', false)->count())->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_question)->toBe('May I buy more disk?')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the public mirror.')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the region in the brief.')
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread')->contains(
            fn (array $command): bool => str_contains((string) ($command['message']['text'] ?? ''), 'Provision the shared database.'),
        ))->toBeTrue();
});

it('continues the consult reviewer when the subtask reaches review', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', 'Yes. The contract allows the intl extension.', cause: 'missed_contract'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Installed intl.'),
    ]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done']);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reviewers = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->get();
    $reviewSend = collect($dispatcher->commands)->last(fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === $reviewer->external_id);
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($reviewers)->toHaveCount(1)
        ->and($reviewers->sole()->id)->toBe($reviewer->id)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
        ->and($reviewSend['message']['text'] ?? null)->toContain('Review subtask #'.$task->id);
});

it('flags the parent when a subtask already asks and the parent does not', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $question = 'Which database should this subtask use?';
    $reason = "The implementer is blocked: The mirror is down.\n\nQuestion: {$question}";
    $task->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('resumes a Pi implementer restarted during the turn instead of asking for assistance', function (): void {
    $task = tick_pi_implementer();
    $notifier = new class implements CoderSettleNotifier
    {
        public bool $called = false;

        public function notify(Task $group): void
        {
            $this->called = true;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->called = true;
        }

        public function assistance(Task $group, string $reason): void
        {
            $this->called = true;
        }
    };
    app()->instance(CoderSettleNotifier::class, $notifier);
    tick_pi_failure((object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.']);

    app(TaskScheduler::class)->tick();

    $sent = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
    $fresh = $task->fresh();
    expect($fresh?->assistance_reason)->toBeNull()
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->status)->toBe(TaskStatus::Running)
        ->and($task->parent->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->called)->toBeFalse()
        ->and($sent)->toBeInstanceOf(Request::class)
        ->and($sent['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".TaskScheduler::PiServerRestartContinue)
        ->and($sent['key'])->not->toBe('turn-key-1')
        ->and($sent['key'])->toBeUuid()
        ->and($fresh?->pi_restart_resumes)->toBe(1)
        ->and($fresh?->pi_restart_key)->toBe($sent['key'])
        ->and($fresh?->pi_restart_source_turn_id)->toBe('turn-key-1')
        ->and($fresh?->pi_restart_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->communication_failures)->toBe(0);
});

it('retries the same Pi resume key while that turn is still unaccepted', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.'];
    tick_pi_failure($state);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $reserved = $fresh?->pi_restart_key;
    expect($reserved)->toBeString()->not->toBe('turn-key-1')
        ->and(tick_pi_message_keys())->toBe([$reserved, $reserved])
        ->and($fresh?->pi_restart_resumes)->toBe(1)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->assistance_requested)->toBeFalse();
});

it('asks for assistance when a Pi implementer fails for another reason', function (): void {
    $task = tick_pi_implementer();
    tick_pi_failure((object) ['turnId' => 'turn-key-1', 'error' => 'The turn was interrupted.']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->parent->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->parent->fresh()?->assistance_question)->toBeNull()
        ->and($task->parent->fresh()?->assistance_requested)->toBeTrue()
        ->and(tick_pi_message_keys())->toBe([])
        ->and($task->fresh()?->pi_restart_resumes)->toBe(0);
});

it('does not replace an open direction request when the implementer thread fails', function (): void {
    $task = tick_pi_implementer();
    $question = 'May the Node user run sudo?';
    $reason = "The implementer is blocked: sudo was denied.\n\nQuestion: {$question}";
    $task->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    $task->parent->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    tick_pi_failure((object) ['turnId' => 'turn-key-1', 'error' => 'The turn was interrupted.']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($task->fresh()?->assistance_reason)->toBe($reason)
        ->and($task->parent->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->parent->fresh()?->assistance_question)->toBe($question);
});

it('asks for assistance after two reserved Pi resumes', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $first = $task->fresh()?->pi_restart_key;
    expect($first)->toBeString()->and($task->fresh()?->pi_restart_resumes)->toBe(1);

    $state->turnId = (string) $first;
    app(TaskScheduler::class)->tick();
    $second = $task->fresh()?->pi_restart_key;
    expect($second)->toBeString()->not->toBe($first)
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_source_turn_id)->toBe($first)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$first, $second]);

    $state->turnId = (string) $second;
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($second)
        ->and(tick_pi_message_keys())->toBe([$first, $second]);
});

it('retries the stored Pi resume key when the send throws before Pi accepts it', function (): void {
    $task = tick_pi_implementer();
    $posts = 0;
    Http::fake(function (Request $request) use (&$posts) {
        if (str_ends_with($request->url(), '/messages')) {
            $posts++;
            if ($posts <= 2) {
                return Http::response(['error' => ['code' => 'unavailable', 'message' => 'down']], 503);
            }

            return Http::response(['duplicate' => false], 202);
        }
        if (! str_contains($request->url(), '/sessions/implementer-thread')) {
            return Http::response([], 404);
        }

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => 'implementer-thread'],
            'state' => 'failed', 'error' => 'The Pi server restarted during the turn.', 'turnId' => 'turn-key-1', 'entries' => [],
        ]);
    });

    app(TaskScheduler::class)->tick();

    $reserved = $task->fresh()?->pi_restart_key;
    expect($reserved)->toBeString()->not->toBe('turn-key-1')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('pending')
        ->and($task->fresh()?->pi_restart_source_turn_id)->toBe('turn-key-1')
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$reserved, $reserved]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_key)->toBe($reserved)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('pending')
        ->and($task->fresh()?->communication_failures)->toBe(0)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$reserved, $reserved, $reserved]);
});

it('reserves a new Pi resume for a restart during the turn that followed an accepted resume', function (): void {
    $task = tick_pi_implementer();
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([null, null]));
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.', 'state' => 'failed'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $accepted = $task->fresh()?->pi_restart_key;
    expect($accepted)->toBeString()->and($task->fresh()?->pi_restart_resumes)->toBe(1);

    $state->state = 'idle';
    $state->turnId = (string) $accepted;
    $state->error = null;
    app(TaskScheduler::class)->tick();

    $reminder = tick_pi_message_keys()[1] ?? null;
    expect($task->fresh()?->pi_restart_reservation)->toBe('accepted')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_key)->toBe($accepted)
        ->and($reminder)->toBeString()->not->toBe($accepted)
        ->and(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/messages'))->values()[1][0]['text'])
        ->toContain('No turn receipt was found.');

    $state->state = 'failed';
    $state->turnId = (string) $reminder;
    $state->error = 'The Pi server restarted during the turn.';
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $keys = tick_pi_message_keys();
    expect($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->pi_restart_resumes)->toBe(2)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->pi_restart_source_turn_id)->toBe($reminder)
        ->and($fresh?->pi_restart_key)->toBeString()->not->toBe($accepted)->not->toBe($reminder)
        ->and($keys)->toBe([$accepted, $reminder, $fresh?->pi_restart_key])
        ->and(array_count_values($keys)[$accepted])->toBe(1);
});

it('does not reset Pi resumes when a resolution is delivered and asks after the cap', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $first = (string) $task->fresh()?->pi_restart_key;
    $state->turnId = $first;
    app(TaskScheduler::class)->tick();
    $second = (string) $task->fresh()?->pi_restart_key;
    $state->turnId = $second;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeTrue();

    app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Continue from the checkout.', 'author' => 'operator',
    ]);

    $afterResolution = $task->fresh();
    expect($afterResolution?->assistance_requested)->toBeFalse()
        ->and($afterResolution?->pi_restart_resumes)->toBe(2)
        ->and($afterResolution?->pi_restart_key)->toBe($second)
        ->and($afterResolution?->pi_restart_reservation)->toBe('accepted')
        ->and($afterResolution?->pi_restart_source_turn_id)->toBe($first)
        ->and($afterResolution?->pi_restart_thread_id)->toBe($task->implementer_agent_thread_id);

    $state->turnId = 'resolution-turn';
    app(TaskScheduler::class)->tick();

    $keys = tick_pi_message_keys();
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($second)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('accepted')
        ->and(array_count_values($keys)[$second] ?? 0)->toBe(1)
        ->and($keys)->not->toContain('resolution-turn');
});

it('does not send an implementer Pi resume to the reviewer', function (): void {
    $task = tick_pi_implementer();
    $reviewer = AgentThread::query()->findOrFail($task->parent->reviewer_agent_thread_id);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([app(PiDriver::class)]));
    $reviewer->update(['driver' => 'pi']);
    $implementer = (object) ['state' => 'failed', 'error' => 'The Pi server restarted during the turn.', 'turnId' => 'impl-turn'];
    $review = (object) ['state' => 'idle', 'error' => null, 'turnId' => 'old-review'];
    /** @var list<array{session: string, key: string, text: string}> $messages */
    $messages = [];
    Http::fake(function (Request $request) use ($implementer, $review, &$messages) {
        if (preg_match('#/sessions/([^/]+)/messages$#', $request->url(), $match) === 1) {
            $messages[] = ['session' => $match[1], 'key' => (string) $request['key'], 'text' => (string) $request['text']];

            return Http::response(['duplicate' => false], 202);
        }
        if (preg_match('#/sessions/([^/]+)$#', $request->url(), $match) !== 1) {
            return Http::response([], 404);
        }
        $snap = $match[1] === 'reviewer-thread' ? $review : $implementer;

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => $match[1]],
            'state' => $snap->state, 'error' => $snap->error, 'turnId' => $snap->turnId, 'entries' => [],
        ]);
    });

    app(TaskScheduler::class)->tick();

    $implementerKey = $task->fresh()?->pi_restart_key;
    expect($implementerKey)->toBeString()
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($messages)->toHaveCount(1)
        ->and($messages[0]['session'])->toBe('implementer-thread')
        ->and($messages[0]['key'])->toBe($implementerKey);

    $task->parent->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $task->review_attempt,
        'review_notified_turn_id' => 'handoff-turn',
        ...tick_review_baseline(),
    ]);
    $review->state = 'failed';
    $review->error = 'The Pi server restarted during the turn.';
    $review->turnId = 'review-turn';
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $reviewerKey = $fresh?->pi_restart_key;
    expect($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->pi_restart_resumes)->toBe(2)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->pi_restart_thread_id)->toBe($reviewer->id)
        ->and($fresh?->pi_restart_source_turn_id)->toBe('review-turn')
        ->and($reviewerKey)->toBeString()->not->toBe($implementerKey)
        ->and($messages)->toHaveCount(2)
        ->and($messages[1]['session'])->toBe('reviewer-thread')
        ->and($messages[1]['key'])->toBe($reviewerKey)
        ->and($messages[1]['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".TaskScheduler::PiServerRestartContinue)
        ->and(collect($messages)->where('session', 'reviewer-thread')->pluck('key')->all())->not->toContain($implementerKey);

    $review->turnId = (string) $reviewerKey;
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The reviewer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($reviewerKey)
        ->and($messages)->toHaveCount(2);
});

it('asks for assistance when a Pi restart has no turn id', function (): void {
    $task = tick_pi_implementer();
    tick_pi_failure((object) ['turnId' => '', 'error' => 'The Pi server restarted during the turn.']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and(tick_pi_message_keys())->toBe([])
        ->and($task->fresh()?->pi_restart_resumes)->toBe(0)
        ->and($task->fresh()?->pi_restart_key)->toBeNull();
});

it('does not resume a recorded T3 thread after a restart failure', function (string $error): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->implementerThread->update(['driver' => 't3']);
    app(TaskExtensionState::class)->enable();
    // A synthetic observation reaches the scheduler's driver guard without a T3 runtime.
    $driver = new FakeAgentDriver('t3');
    $driver->observation = new AgentObservation(AgentThreadState::Failed, error: $error, turnId: 'old-turn');
    $pi = new FakeAgentDriver('pi');
    $pi->observation = new AgentObservation(AgentThreadState::Idle);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver, $pi]));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($driver->calls)->toBe([])
        ->and($task->fresh()?->pi_restart_resumes)->toBe(0)
        ->and($task->fresh()?->pi_restart_key)->toBeNull();
})->with([
    'Pi restart text on an old T3 row' => [TaskScheduler::PiServerRestartError],
    'retired T3 orphaned session error' => ['Provider session did not survive a server restart. Send a new message to continue.'],
    'retired T3 continuation error' => ['Could not continue this thread after the server restart. Send a new message to continue.'],
]);

it('keeps a Pi resume counted when the server accepts the key and both responses fail', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.', 'state' => 'failed'];
    $accepted = [];
    $failedKey = null;
    Http::fake(function (Request $request) use ($state, &$accepted, &$failedKey) {
        if (str_ends_with($request->url(), '/messages')) {
            $key = (string) $request['key'];
            $accepted[$key] = true;
            $failedKey ??= $key;
            if ($key === $failedKey) {
                return Http::response(['error' => ['code' => 'unavailable', 'message' => 'down']], 503);
            }

            return Http::response(['duplicate' => false], 202);
        }
        if (! str_contains($request->url(), '/sessions/implementer-thread')) {
            return Http::response([], 404);
        }

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => 'implementer-thread'],
            'state' => $state->state, 'error' => $state->error, 'turnId' => $state->turnId, 'entries' => [],
        ]);
    });

    app(TaskScheduler::class)->tick();

    $reserved = $task->fresh();
    expect($reserved?->pi_restart_resumes)->toBe(1)
        ->and($reserved?->pi_restart_reservation)->toBe('pending')
        ->and($reserved?->pi_restart_key)->toBeString()
        ->and($accepted)->toHaveKey($reserved?->pi_restart_key)
        ->and($reserved?->communication_failures)->toBe(1)
        ->and($reserved?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$reserved?->pi_restart_key, $reserved?->pi_restart_key]);

    $state->turnId = (string) $reserved?->pi_restart_key;
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $keys = tick_pi_message_keys();
    expect($fresh?->pi_restart_resumes)->toBe(2)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->pi_restart_source_turn_id)->toBe($reserved?->pi_restart_key)
        ->and($fresh?->pi_restart_key)->toBeString()->not->toBe($reserved?->pi_restart_key)
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->communication_failures)->toBe(0)
        ->and(array_count_values($keys)[(string) $reserved?->pi_restart_key])->toBe(2)
        ->and($keys)->toBe([$reserved?->pi_restart_key, $reserved?->pi_restart_key, $fresh?->pi_restart_key]);

    $state->turnId = (string) $fresh?->pi_restart_key;
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($fresh?->pi_restart_key)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('accepted')
        ->and(tick_pi_message_keys())->toBe($keys);
});

it('supersedes a pending Pi resume when a later turn is first observed', function (): void {
    $task = tick_pi_implementer();
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([null, null]));
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.', 'state' => 'failed'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $reserved = (string) $task->fresh()?->pi_restart_key;

    $state->state = 'idle';
    $state->turnId = 'normal-turn';
    $state->error = null;
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $keys = tick_pi_message_keys();
    expect($fresh?->pi_restart_reservation)->toBe('superseded')
        ->and($fresh?->pi_restart_resumes)->toBe(1)
        ->and($fresh?->pi_restart_key)->toBe($reserved)
        ->and($fresh?->pi_restart_source_turn_id)->toBe('turn-key-1')
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($reserved)
        ->and($keys[1])->not->toBe($reserved)
        ->and(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/messages'))->values()[1][0]['text'])
        ->toContain('No turn receipt was found.');

    $state->state = 'failed';
    $state->turnId = 'normal-turn';
    $state->error = 'The Pi server restarted during the turn.';
    app(TaskScheduler::class)->tick();

    $after = $task->fresh();
    expect($after?->pi_restart_resumes)->toBe(2)
        ->and($after?->pi_restart_reservation)->toBe('pending')
        ->and($after?->pi_restart_source_turn_id)->toBe('normal-turn')
        ->and($after?->pi_restart_key)->toBeString()->not->toBe($reserved)->not->toBe($keys[1])
        ->and($after?->assistance_requested)->toBeFalse()
        ->and(array_count_values(tick_pi_message_keys())[$reserved])->toBe(1);
});

it('reminds an implementer that ends a turn without a receipt once, then asks for assistance', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle');
        }
    });
    $receipts = new FakeTaskTurnReceipts([null, null]);
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the brief is complete. No turn receipt was found. '.TaskTurnInstructions::implementer(check: $group->project->taskCheckCommand(), threadId: $task->implementer_agent_thread_id))
        ->and($receipts->prepared)->toBe(['implementer'])
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toBe('Checks still failed after the reminder. No turn receipt was found.')
        ->and($task->comments()->count())->toBe(0);
    Classification::assertNothingClassified();
});

it('refuses a receipt with an outcome that does not fit the implementer turn, or a blocked receipt without a question', function (string $contents): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $receipts = new FakeTaskTurnReceipts([$contents]);
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands[0]['message']['text'])->toContain('The turn receipt was not valid for this turn.')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('--question=')
        ->and($task->comments()->count())->toBe(0)
        ->and($receipts->cleared)->toHaveCount(1);
})->with([
    'a reviewer outcome' => [FakeTaskTurnReceipts::contents('approved')],
    'blocked without a question' => [FakeTaskTurnReceipts::contents('blocked', 'Gateway implementation not completed; required project guidance/bootstrap review and implementation remain.')],
]);

it('dispatches nothing when Jev selects noop', function (): void {
    tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public bool $called = false;

        public function notify(Task $group): void
        {
            $this->called = true;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->called = true;
        }

        public function assistance(Task $group, string $reason): void
        {
            $this->called = true;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($notifier->called)->toBeFalse();
});

it('runs the artisan tick while the extension is enabled', function (): void {
    app(TaskExtensionState::class)->enable();

    $this->artisan('tasks:tick')
        ->expectsOutput('Routed [0] tasks and started [0] groups.')
        ->assertSuccessful();
});

it('takes the tick lock in the default cache store when CACHE_STORE is unset', function (): void {
    /** @var array{default: string} $cache */
    $cache = config_without_env('cache.php', ['CACHE_STORE']);
    config(['cache.default' => $cache['default']]);
    Cache::lock('orbit:tasks:tick')->forceRelease();
    app(TaskExtensionState::class)->enable();

    $this->artisan('tasks:tick')
        ->expectsOutput('Routed [0] tasks and started [0] groups.')
        ->assertSuccessful();

    $held = Cache::store('file')->lock('orbit:tasks:tick', 300);
    expect($held->get())->toBeTrue();
    $this->artisan('tasks:tick')
        ->expectsOutput('Another tasks tick is already running.')
        ->assertSuccessful();
    $held->release();
});

it('does not classify or advance a task while its agent thread is active', function (string $status): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class($status) implements AgentSnapshotReader
    {
        public function __construct(private string $status) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => $threadId === 'implementer-thread' ? $this->status : 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'Done. Ready for review.']],
            ]];
        }
    });
    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->status)->toBe(TaskStatus::Running);
})->with(['starting', 'running']);

it('ignores tasks that are not in progress even when they have a thread', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->first();
    $task->update(['status' => $status]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            throw new LogicException('Only in-progress tasks should be inspected.');
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($task->fresh()->status)->toBe($status);
    Classification::assertNothingClassified();
})->with([TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled]);

it('does not classify an in-progress task without an attached session', function (): void {
    $group = tick_group();
    $group->tasks->first()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_id', $group->tasks->first()->id)->delete();
    app(TaskExtensionState::class)->enable();

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([]);
    Classification::assertNothingClassified();
});

it('targets the idle in-progress task while another task is working', function (): void {
    $group = tick_group();
    $workingTask = $group->tasks->first();
    $workingTask->update(['status' => TaskStatus::Reviewing]);
    $idleTask = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Second task',
        'brief' => 'Finish the second task.',
        'status' => TaskStatus::Running,
    ]);
    test_agent_thread($group, 'second-task-session', $idleTask);
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSpawner::class, new NullAgentSpawner);
    $reader = new class implements AgentSnapshotReader
    {
        /** @var list<string> */
        public array $requested = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->requested[] = $threadId;

            return ['thread' => [
                'session' => ['status' => $threadId === 'implementer-thread' ? 'running' : 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'Ready for the next step.']],
            ]];
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);
    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->where('task_id', $idleTask->id)->count())->toBe(1)
        ->and(TaskCheck::query()->where('task_id', $workingTask->id)->count())->toBe(0)
        ->and($workingTask->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($idleTask->fresh()->status)->toBe(TaskStatus::Running);
});

it('reminds the implementer with the failing check output once, then asks for assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED tests/Feature/ExportTest.php\n");
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed, $failed]));
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'), null, FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed the export test.'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toContain("Orbit ran composer check, and it failed with exit code 1. The end of its output:\n\n```\nFAILED tests/Feature/ExportTest.php\n```")
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toStartWith('Checks still failed after the reminder. Orbit ran composer check, and it failed with exit code 1.')
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and(TaskCheck::query()->count())->toBe(2);
});

it('shows an unexpected check error as a failed check whose output ends with the error', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $output = "composer check\n\nTraceback (most recent call last):\nFileNotFoundError: [Errno 2] No such file or directory: 'gateway'\n";
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $output, failedStep: 'check_error');
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed]));
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($reminder)->toContain('Orbit ran composer check, and it failed with exit code 1.')
        ->and($reminder)->toContain("FileNotFoundError: [Errno 2] No such file or directory: 'gateway'")
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed)
        ->and(TaskCheck::query()->sole()->failed_step)->toBe('check_error');
});

it('keeps an unexpected check error failed when the tree changed during the run', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $output = "rm -rf app\n\nTraceback (most recent call last):\nFileNotFoundError: [Errno 2] No such file or directory\n";
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('c', 40), ['app'], $output, failedStep: 'check_error');
    $checks = new FakeTaskCheckRunner([$failed]);
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    $check = TaskCheck::query()->sole();
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($checks->starts)->toBe(1)
        ->and($check->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->failed_step)->toBe('check_error')
        ->and($check->changed_paths)->toBe(['app'])
        ->and($reminder)->toContain('FileNotFoundError: [Errno 2] No such file or directory')
        ->and($reminder)->not->toContain('workspace changed');
});

it('retries the reviewer nudge until the handoff send succeeds', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $spawner = new class implements AgentSpawner
    {
        public int $spawns = 0;

        public function spawnReviewer(Task $task): ?int
        {
            $this->spawns++;
            if ($this->spawns === 1) {
                return null;
            }
            $thread = test_agent_thread($task->parent, 'reviewer-spawned');
            $thread->update(['task_id' => $task->id]);

            return $thread->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle');
        }
    });

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $task = $group->tasks()->first();
    expect($spawner->spawns)->toBe(1)
        ->and($task?->status)->toBe(TaskStatus::Reviewing)
        ->and($task?->review_notified_attempt)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($spawner->spawns)->toBe(2)
        ->and($task?->fresh()?->review_notified_attempt)->toBe($task?->review_attempt)
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($spawner->spawns)->toBe(2)
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('waits for a newer reviewer turn before asking for an outcome', function (?string $observedTurn): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $attempt = $task->fresh()?->review_attempt ?? 1;
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $attempt,
        'review_notified_turn_id' => 'turn-old',
    ]);
    $reader = new class($observedTurn) implements AgentSnapshotReader
    {
        public function __construct(public ?string $turnId) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'done'],
                'latestTurn' => ['id' => $this->turnId, 'state' => 'completed'],
            ]];
        }
    };
    $dispatcher = tick_dispatcher();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([null]));

    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    $reader->turnId = 'turn-new';
    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('No turn receipt was found.');
})->with(['turn-old', null, '']);

it('retries review findings until the implementer receives them', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, ...tick_review_baseline()]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('changes_requested', 'Add the missing test.')]));
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public int $calls = 0;

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->calls++;
            $this->commands[] = $command;
            if ($this->calls === 1) {
                throw new AgentDriverException('relay failed');
            }

            return ['sequence' => $this->calls, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('changes_requested')
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($dispatcher->commands[1]['message']['text'])->toContain('Add the missing test.')
        ->and($dispatcher->commands[1]['message']['text'])->toContain(TaskTurnInstructions::implementer(check: $group->project->taskCheckCommand(), threadId: $task->implementer_agent_thread_id))
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['implementer', 'implementer']);
});

it('repeated reminder send failures reach assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'waiting'], 'pendingApprovals' => [['requestId' => 'pending-1']]]];
        }
    });
    app()->instance(AgentCommandDispatcher::class, new class implements AgentCommandDispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            throw new AgentDriverException('send failed');
        }
    });
    for ($i = 0; $i < 6; $i++) {
        app(TaskScheduler::class)->tick();
    }
    expect($group->tasks()->sole()->communication_failures)->toBeGreaterThanOrEqual(5);
    expect($group->fresh()->assistance_requested)->toBeTrue();
});

it('handoff waits while reviewer still reports its old turn', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread($threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? 'done' : 'running');
            $snapshot['thread']['latestTurn'] = [
                'id' => $threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? $threadId.'-old' : 'opening',
                'state' => $threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? 'completed' : 'running',
            ];

            return $snapshot;
        }
    });
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $reviewerId = $group->fresh()?->reviewer_agent_thread_id;
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Reviewing)
        ->and(array_column($dispatcher->commands, 'type'))->toBe(['create'])
        ->and(AgentThread::query()->find($reviewerId)?->task_id)->toBe($group->tasks()->sole()->id);
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toHaveCount(1);
});

it('unavailable implementer uses observation grace instead of rubric', function (): void {
    $group = tick_group();
    AgentThread::query()->where('task_id', $group->tasks->sole()->id)->update(['state' => 'done']);
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toBe([]);
    expect($group->fresh()->agent_unavailable_since)->not->toBeNull();
});
it('an unavailable reviewer cannot advance an approval', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskTurnReceipts::contents('approved')]);
    AgentThread::query()->where('task_group_id', $group->id)->update(['state' => 'done']);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($receipts->reads())->toBe(0)
        ->and($signer->messages)->toBe([]);
});

it('relayed findings require a newer implementer turn, a new receipt, and a new check before review', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, ...tick_review_baseline()]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('changes_requested', 'Fix the regression before requesting review again.'),
        null,
        FakeTaskTurnReceipts::contents('ready_for_review'),
    ]));
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    $reader = new class implements AgentSnapshotReader
    {
        public string $turnId = 'before-findings';

        public string $checkId = 'check-1';

        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread('done');
            $snapshot['thread']['latestTurn'] = ['id' => $this->turnId, 'state' => 'completed'];
            $snapshot['thread']['activities'][0]['id'] = $this->checkId;

            return $snapshot;
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(AgentCommandDispatcher::class)->commands)->toHaveCount(1);
    Classification::assertNothingClassified();

    $reader->turnId = 'after-findings';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(AgentCommandDispatcher::class)->commands[1]['message']['text'])->toContain('No turn receipt was found.');

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Reviewing);
});

it('retains reminder send failures across successful classifications and clears them on delivery', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running]);
    $task->update(['status' => $status, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'old-turn']);
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([]));
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'new-turn', 'state' => 'completed']]];
        }
    });
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public bool $fails = true;

        public function dispatch(Node $node, array $command): array
        {
            if ($this->fails) {
                throw new AgentDriverException('send failed');
            }

            return ['sequence' => 1, 'thread_id' => $command['threadId']];
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(2);

    $dispatcher->fails = false;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
})->with([TaskStatus::Running, TaskStatus::Reviewing]);

/**
 * A task in review whose reviewer has finished the turn after the handoff. Unless it is the last,
 * a later subtask waits behind it.
 *
 * @param  list<string|null>  $receipts
 * @return array{Task, Task, FakeTaskTurnReceipts, object, object}
 */
/** @return array{review_workspace_head: string, review_workspace_tree: string} */
function tick_review_baseline(): array
{
    return [
        'review_workspace_head' => str_repeat('a', 40),
        'review_workspace_tree' => str_repeat('b', 40),
    ];
}

function tick_review(array $receipts, bool $onBranch = true, bool $last = false): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    if (! $last) {
        Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Routes', 'brief' => 'Add the routes.', 'status' => TaskStatus::Todo]);
    }
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn', ...tick_review_baseline()]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });
    tick_workspace(branch: $onBranch ? 'task-'.$group->id : 'main');
    $receipts = new FakeTaskTurnReceipts($receipts);
    app()->instance(TaskTurnReceipts::class, $receipts);
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public bool $fails = false;

        public function commit(Instance $instance, string $message): ?string
        {
            $this->messages[] = $message;
            if ($this->fails) {
                return null;
            }
            $sha = str_repeat('c', 40);
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = $sha;
            }

            return $sha;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    $publisher = new class implements TaskPullRequestPublisher
    {
        /** @var list<int> */
        public array $pushes = [];

        /** @var list<string> */
        public array $bodies = [];

        public int $pushFailures = 0;

        public function publish(Task $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function push(Task $group, string $commit): void
        {
            $this->pushes[] = $group->id;
            if ($this->pushFailures > 0) {
                $this->pushFailures--;

                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }
    };
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    return [$group->fresh(['project', 'tasks', 'taskable']) ?? $group, $task, $receipts, $signer, $publisher];
}

it('does not apply a receipt from the thread named by a stale turn file', function (): void {
    [$group, $task] = tick_review([null]);
    $acting = (int) $group->reviewer_agent_thread_id;
    app()->instance(TaskTurnReceipts::class, new class($acting) implements TaskTurnReceipts
    {
        public function __construct(private int $acting) {}

        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void {}

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            expect($actingThreadId)->toBe($this->acting);

            return TaskTurnReceipt::parse((string) json_encode([
                'outcome' => 'approved', 'summary' => 'Approved the earlier subtask.', 'thread' => $this->acting + 1,
            ], JSON_THROW_ON_ERROR));
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(0)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('does not apply an unbound legacy receipt and reissues the bound turn command', function (): void {
    [$group, $task] = tick_review([null]);
    $acting = (int) $group->reviewer_agent_thread_id;
    $receipts = new class implements TaskTurnReceipts
    {
        public bool $legacy = true;

        /** @var list<int|null> */
        public array $preparedThreads = [];

        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void
        {
            $this->preparedThreads[] = $threadId;
            $this->legacy = false;
        }

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return TaskTurnReceipt::parse((string) json_encode([
                'outcome' => 'approved', 'summary' => 'Approved the earlier subtask.',
            ], JSON_THROW_ON_ERROR));
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return $this->legacy;
        }
    };
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    $text = (string) data_get(app(AgentCommandDispatcher::class), 'commands.0.message.text');

    expect($task->comments()->count())->toBe(0)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($receipts->preparedThreads)->toBe([$acting])
        ->and($text)->toContain('Orbit bound this turn to its thread.')
        ->and($text)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread='.$acting.' --outcome=');
});

it('applies a receipt that names the acting reviewer', function (): void {
    [$group, $task] = tick_review([null], last: true);
    $acting = (int) $group->reviewer_agent_thread_id;
    app()->instance(TaskTurnReceipts::class, new class($acting) implements TaskTurnReceipts
    {
        public function __construct(private int $acting) {}

        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void {}

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return TaskTurnReceipt::parse((string) json_encode([
                'outcome' => 'approved', 'summary' => 'Checked this subtask.', 'thread' => $actingThreadId,
            ], JSON_THROW_ON_ERROR));
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->comments()->sole()->getRawOriginal('type'))->toBe('approved')
        ->and($task->comments()->sole()->body)->toBe('Checked this subtask.');
});

it('commits an approved subtask with the title and the reviewer summary, then starts the next subtask', function (): void {
    [$group, $task, $receipts, $signer, $publisher] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked the models and their tests.')]);
    $next = Task::query()->where('title', 'Routes')->sole();
    $spawner = new class implements AgentSpawner
    {
        /** @var list<int> */
        public array $implementers = [];

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers[] = $task->id;

            return test_agent_thread($task->parent, 'implementer-'.$task->id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    app(TaskScheduler::class)->tick();

    $approval = $task->comments()->sole();
    expect($signer->messages)->toBe(["Models\n\nChecked the models and their tests."])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($next->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($spawner->implementers)->toBe([$next->id])
        ->and($approval->getRawOriginal('type'))->toBe('approved')
        ->and($approval->author)->toBe('reviewer')
        ->and($approval->review_attempt)->toBe($task->review_attempt)
        ->and($approval->commit_sha)->toBe(str_repeat('c', 40))
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($publisher->bodies)->toBe([])
        ->and($task->fresh()?->review_handled_comment_id)->toBe($approval->id)
        ->and($receipts->cleared)->toHaveCount(1);
    Classification::assertNothingClassified();
});

it('retries the commit of an approved subtask without storing the approval twice', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Looks good.')]);
    $signer->fails = true;

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1);

    $signer->fails = false;
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($signer->messages)->toHaveCount(2);
});

it('does not commit an approval while the workspace is on another branch', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Looks good.'), null], onBranch: false);

    app(TaskScheduler::class)->tick();

    $dispatcher = app(AgentCommandDispatcher::class);
    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($dispatcher->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the review is complete. The workspace branch is not task-'.$group->id.'. Switch back to it. '.TaskTurnInstructions::reviewer(threadId: $group->reviewer_agent_thread_id));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.');
});

it('asks for assistance with the summary of a blocked reviewer receipt', function (): void {
    [$group, $task] = tick_review([FakeTaskTurnReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?', [], 'contract_gap')]);

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe('Should the subtask follow the brief or ADR 0098?')
        ->and($task->fresh()?->assistance_reason)->toBe("The reviewer is blocked: The brief contradicts ADR 0098.\n\nQuestion: Should the subtask follow the brief or ADR 0098?")
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe('Should the subtask follow the brief or ADR 0098?')
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
        ->and($task->comments()->sole()->cause)->toBe(QuestionCause::ContractGap)
        ->and($question->asked_by)->toBe(QuestionAsker::Reviewer)
        ->and($question->cause)->toBe(QuestionCause::ContractGap)
        ->and($question->status)->toBe(QuestionStatus::Escalated)
        ->and($question->escalated_at)->not->toBeNull()
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(1)
        ->and($group->fresh()?->questions)->toBe(1)
        ->and($group->fresh()?->escalations)->toBe(1);
});

it('answers a direction question from the reviewer receipt that follows the operator resolution', function (): void {
    [$group, $task] = tick_review([
        FakeTaskTurnReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?', [], 'contract_gap'),
        FakeTaskTurnReceipts::contents('changes_requested', 'Follow the ADR.', cause: 'missed_contract'),
    ]);
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    app(TaskScheduler::class)->tick();

    app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Follow ADR 0098.', 'author' => 'operator',
    ]);
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Escalated)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->not->toBeNull()
        ->and(app(TaskTurnReceipts::class)->modes)->toContain('cause')
        ->and(app(TaskTurnReceipts::class)->modes)->not->toContain('relay');
    $reviewerSend = collect(app(AgentCommandDispatcher::class)->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'send');
    expect($reviewerSend['message']['text'] ?? null)->toContain('Follow ADR 0098.')
        ->and($reviewerSend['message']['text'] ?? null)->not->toContain('This is a relay');

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answered_by)->toBe(QuestionAsker::Operator)
        ->and($question->answer)->toBe('Follow ADR 0098.')
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($question->escalated_at)->not->toBeNull()
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(1)
        ->and($group->fresh()?->escalations)->toBe(1);
});

it('leaves a direction question escalated when the following review receipt has no cause', function (): void {
    [$group, $task] = tick_review([
        FakeTaskTurnReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?', [], 'scope'),
        FakeTaskTurnReceipts::contents('changes_requested', 'Follow the ADR.'),
    ]);
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    app(TaskScheduler::class)->tick();
    app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Follow ADR 0098.', 'author' => 'operator',
    ]);

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Escalated)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(AgentCommandDispatcher::class)->commands)->not->toBe([]);
});

it('starts a reviewer for a held direction resolution and answers from the relay', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->update([
        'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which mirror?', 'assistance_reason' => 'Which mirror?',
    ]);
    $group->update([
        'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which mirror?', 'assistance_reason' => 'Which mirror?',
    ]);
    TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'contract_gap'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'idle'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $completion = $task->completion_attempt;
    $review = $task->review_attempt;

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the public mirror.', 'author' => 'operator',
    ]);

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->direction_relay_comment_id)->toBe($comment->id)
        ->and($receipts->modes)->not->toBeEmpty()
        ->and($receipts->modes)->each->toBe('relay')
        ->and(AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->exists())->toBeTrue();

    app(TaskScheduler::class)->tick();

    $delivered = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread');
    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::ContractGap)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and($delivered['message']['text'] ?? null)->toContain('Use the public mirror.');
});

it('keeps a direction relay and its question pending through a topology request', function (): void {
    [$group, $task, $resolution, $question] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('topology_requested', 'Need Nodes to interpret direction.'),
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'scope'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'idle'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $topology = new FakeTaskWorkspaceTopology;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    app(TaskScheduler::class)->tick();

    expect($question->fresh()->status)->toBe(QuestionStatus::Escalated)
        ->and($question->fresh()->cause)->toBeNull()
        ->and($task->fresh()->direction_relay_comment_id)->toBe($resolution->id)
        ->and($task->fresh()->assistance_requested)->toBeFalse()
        ->and(end($receipts->modes))->toBe('relay')
        ->and(json_encode($dispatcher->commands))->toContain('is ready.');

    $state->reviewerTurnId = 'relay-after-topology-request';
    app(TaskScheduler::class)->tick();
    expect($question->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()->direction_relay_comment_id)->toBeNull();
});

it('keeps a relay answer while the implementer is busy and delivers it once the implementer is free', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'scope'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'running'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $completion = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    expect($receipts->reads())->toBe(1)
        ->and($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::Scope)
        ->and($task->fresh()?->completion_attempt)->toBe($completion);

    $state->implementer = 'idle';
    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::Scope)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread'))->toHaveCount(1);
});

it('retries a relay answer after the implementer send fails without opening another question', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'environment'),
    ]);
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public int $failuresLeft = 1;

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            if ($this->failuresLeft > 0 && ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->failuresLeft--;

                throw new AgentDriverException('turn start failed');
            }
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'idle']);
    $completion = $task->completion_attempt;
    $review = $task->review_attempt;

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::Environment)
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and($dispatcher->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->not->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread'))->toHaveCount(1);
});

it('asks for help when a relay answer command stays rejected', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'missed_contract'),
    ]);
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<string> */
        public array $rejected = [];

        /** @var list<string> */
        public array $attempts = [];

        public function dispatch(Node $node, array $command): array
        {
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $commandId = (string) ($command['commandId'] ?? '');
                $this->attempts[] = $commandId;
                if (in_array($commandId, $this->rejected, true)) {
                    throw new AgentDriverException('OrchestrationCommandPreviouslyRejectedError');
                }
                $this->rejected[] = $commandId;

                throw new AgentDriverException('command rejected');
            }

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'idle']);
    $completion = $task->completion_attempt;
    $review = $task->review_attempt;

    foreach (range(1, 4) as $attempt) {
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->communication_failures)->toBe($attempt)
            ->and(TaskQuestion::query()->count())->toBe(1)
            ->and($task->fresh()?->completion_attempt)->toBe($completion)
            ->and($task->fresh()?->review_attempt)->toBe($review);
    }

    app(TaskScheduler::class)->tick();

    $key = $dispatcher->attempts[0];
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and($dispatcher->attempts)->toHaveCount(5)
        ->and(array_unique($dispatcher->attempts))->toBe([$key])
        ->and($dispatcher->rejected)->toBe([$key])
        ->and($task->fresh()?->direction_answer_key)->toBe($key);

    app(TaskScheduler::class)->tick();

    expect($dispatcher->attempts)->toHaveCount(5)
        ->and(TaskQuestion::query()->count())->toBe(1);
});

it('delivers a relay answer that was recorded before the send was interrupted', function (): void {
    [$group, $task, $resolution, $question] = tick_held_relay();
    $receipt = TaskComment::query()->create([
        'task_id' => $task->id, 'task_group_id' => $group->id, 'agent_thread_id' => $group->reviewer_agent_thread_id,
        'review_attempt' => $task->review_attempt, 'type' => 'answered', 'body' => 'Use the public mirror.',
        'cause' => QuestionCause::MissedContract, 'author' => 'reviewer', 'receipt_hash' => hash('sha256', 'interrupted-relay'),
        'posted_at' => now(),
    ]);
    $question->update([
        'status' => QuestionStatus::Answered, 'answered_by' => QuestionAsker::Operator, 'answer' => 'Use the public mirror.',
        'cause' => QuestionCause::MissedContract, 'answered_at' => now(), 'answered_comment_id' => $receipt->id,
    ]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime(new FakeTaskTurnReceipts([null]), $dispatcher, (object) ['implementer' => 'idle']);
    $completion = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->id)->toBe($question->id)
        ->and(TaskQuestion::query()->sole()->answered_comment_id)->toBe($receipt->id)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->toBe($receipt->id)
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread'))->toHaveCount(1);
});

it('replays a held direction review from the stored resolution after the flag write fails', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->update([
        'status' => TaskStatus::Reviewing, 'review_attempt' => 3, 'review_notified_attempt' => null,
        'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which ADR?', 'assistance_reason' => 'Which ADR?',
    ]);
    $group->update([
        'status' => TaskGroupStatus::Reviewing, 'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which ADR?', 'assistance_reason' => 'Which ADR?',
    ]);
    TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 3, 'asked_by' => QuestionAsker::Reviewer,
        'question' => 'Which ADR?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    $questionSeen = false;
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject, &$questionSeen): void {
        if (! $inject) {
            return;
        }
        $statement = ltrim(strtolower($sql));
        if (str_contains($sql, 'task_questions') && str_starts_with($statement, 'update')) {
            $questionSeen = true;

            return;
        }
        if ($questionSeen && str_contains($sql, '"tasks"') && str_starts_with($statement, 'update')) {
            $inject = false;

            throw new RuntimeException('injected task flag failure');
        }
    });
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);

    expect(fn () => app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Follow ADR 0098.', 'author' => 'operator',
    ]))->toThrow(RuntimeException::class);

    $comment = TaskComment::query()->where('type', 'resolution')->sole();
    expect($questionSeen)->toBeTrue()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBeNull()
        ->and(TaskComment::query()->where('type', 'resolution')->count())->toBe(1);

    app(TaskExtensionState::class)->enable();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
    $dispatcher = tick_dispatcher();
    $reader = new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'done']]];
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id)
        ->and(TaskComment::query()->where('type', 'resolution')->count())->toBe(1)
        ->and($comment->fresh()?->review_attempt)->toBe(3);

    app(TaskScheduler::class)->tick();

    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->sole();
    $opening = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'create' && ($command['threadId'] ?? null) === $reviewer->external_id);
    expect($reviewer->id)->toBe($group->fresh()?->reviewer_agent_thread_id)
        ->and($task->fresh()?->resolution_delivered_comment_id)->toBe($comment->id)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and(data_get($opening, 'message.text'))->toContain('Follow ADR 0098.');
});

it('does not send a second implementer turn when an accepted relay answer lost its response', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'scope'),
        null,
        FakeTaskTurnReceipts::contents('ready_for_review', 'The mirror is wired.'),
    ]);
    $state = (object) ['implementer' => 'idle', 'turnId' => 'turn-before', 'messageId' => null];
    $dispatcher = new class($state) implements AgentCommandDispatcher
    {
        public function __construct(private object $state) {}

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->commands[] = $command;
                $this->state->turnId = (string) $command['commandId'];
                $this->state->messageId = (string) $command['commandId'];

                throw new AgentDriverException('response lost');
            }

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, $state);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $completion = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['commandId'])->toBe($task->fresh()?->direction_answer_key)
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and(collect($receipts->prepared)->filter(fn (string $role): bool => $role === 'implementer'))->toHaveCount(1);

    $receipts->discardImplementerReceiptOnPrepare = true;
    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and(collect($receipts->prepared)->filter(fn (string $role): bool => $role === 'implementer'))->toHaveCount(1)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->direction_answer_key)->toBeNull()
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($task->fresh()?->completion_attempt)->toBe($completion);

    app(TaskScheduler::class)->tick();

    expect(TaskComment::query()->where('type', 'ready_for_review')->count())->toBe(1)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('keeps the implementer handoff when the relay marker update fails after the send', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'contract_gap'),
        null,
        FakeTaskTurnReceipts::contents('ready_for_review', 'The mirror is wired.'),
    ]);
    $state = (object) ['implementer' => 'idle', 'turnId' => 'turn-before', 'messageId' => null];
    $dispatcher = new class($state) implements AgentCommandDispatcher
    {
        public function __construct(private object $state) {}

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->state->turnId = 'turn-after';
                $this->state->messageId = null;
            }

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, $state);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject): void {
        if ($inject && str_contains($sql, 'review_handled_comment_id')) {
            $inject = false;

            throw new RuntimeException('injected marker failure');
        }
    });
    $completion = $task->completion_attempt;
    $starts = fn (): int => collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread')->count();

    expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class);

    expect($starts())->toBe(1)
        ->and($dispatcher->commands[0]['commandId'] ?? null)->toBe($task->fresh()?->direction_answer_key)
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->toBeNull();

    $receipts->discardImplementerReceiptOnPrepare = true;
    app(TaskScheduler::class)->tick();

    expect($starts())->toBe(1)
        ->and(collect($receipts->prepared)->filter(fn (string $role): bool => $role === 'implementer'))->toHaveCount(1)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($task->fresh()?->completion_attempt)->toBe($completion);

    app(TaskScheduler::class)->tick();

    expect(TaskComment::query()->where('type', 'ready_for_review')->count())->toBe(1)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($dispatcher->commands)->not->toBeEmpty()
        ->and($starts())->toBe(1);

});

it('reminds a reviewer that ends a turn without a receipt once, then asks for assistance', function (): void {
    [$group, $task, $receipts] = tick_review([null, null]);

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::reviewer(threadId: $group->reviewer_agent_thread_id))
        ->and($receipts->prepared)->toBe(['reviewer']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.');
    Classification::assertNothingClassified();
});

it('refuses an implementer outcome in a reviewer turn', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskTurnReceipts::contents('ready_for_review')]);

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The turn receipt was not valid for this turn.')
        ->and($task->comments()->count())->toBe(0)
        ->and($signer->messages)->toBe([])
        ->and($receipts->cleared)->toHaveCount(1);
});

it('starts the reviewer with the first review request when the group has no reviewer yet', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->delete();
    $group->update(['status' => TaskGroupStatus::Reviewing, 'reviewer_agent_thread_id' => null]);
    $task->update(['status' => TaskStatus::Reviewing]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $spawner = new class implements AgentSpawner
    {
        /** @var list<int> */
        public array $reviewers = [];

        public function spawnReviewer(Task $task): ?int
        {
            $this->reviewers[] = $task->id;

            return test_agent_thread($task->parent, 'reviewer-thread-2')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($spawner->reviewers)->toBe([$task->id])
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'reviewer-thread-2')->sole()->id)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['reviewer:final']);
});

function tick_final_approval(): string
{
    return json_encode([
        'outcome' => 'approved',
        'summary' => 'Checked the feature.',
        'pull_request' => ['summary' => 'Adds tick routing.', 'changes' => ['Tasks store their records.'], 'breaking' => []],
        'nonce' => bin2hex(random_bytes(8)),
    ], JSON_THROW_ON_ERROR);
}

/** @param list<list<string>> $missing */
function tick_publishing(array $missing = [[]], int $failures = 0): object
{
    $coverage = new class($missing) implements TaskBriefCoverage
    {
        public int $calls = 0;

        public ?int $approvalCommentId = null;

        /** @var list<string>|null */
        public ?array $approvalChanges = null;

        /** @param list<list<string>> $missing */
        public function __construct(private array $missing) {}

        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            $this->calls++;
            $this->approvalCommentId = $approvalCommentId;
            $this->approvalChanges = $approvalChanges;

            return array_shift($this->missing) ?? [];
        }
    };
    $publisher = new class($failures) implements TaskPullRequestPublisher
    {
        /** @var list<string> */
        public array $bodies = [];

        /** @var list<int> */
        public array $pushes = [];

        public function __construct(private int $failures) {}

        public function publish(Task $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;
            if ($this->failures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function push(Task $group, string $commit): void
        {
            $this->pushes[] = $group->id;
        }
    };
    app()->instance(TaskBriefCoverage::class, $coverage);
    app()->instance(TaskPullRequestPublisher::class, $publisher);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);

    return (object) ['coverage' => $coverage, 'publisher' => $publisher];
}

it('continues an approval tick after Jev recording fails and reaches later work', function (): void {
    [$group, $task, , $signer, $publisher] = tick_review([tick_final_approval()], last: true);
    Classification::fake([['subtask_'.$task->id => new BooleanAnswer(0.97)]])->preventStrayClassifications();
    Exceptions::fake();
    DB::statement("CREATE TRIGGER fail_jev_decision_insert_during_tick BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
    Exceptions::assertReported(QueryException::class);
});

it('commits the last approved subtask, opens the pull request with the reviewer fields, and settles the group', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    $approval = $task->comments()->sole();
    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publishing->publisher->pushes)->toBe([$group->id])
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskTurnPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 1, $group->project->taskCheckCommand())])
        ->and($approval->pull_request)->toBe(['summary' => 'Adds tick routing.', 'changes' => ['Tasks store their records.'], 'breaking' => []])
        ->and($publishing->coverage->approvalCommentId)->toBe($approval->id)
        ->and($publishing->coverage->approvalChanges)->toBe(['Tasks store their records.'])
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('counts only the delivered subtasks in the pull request description', function (): void {
    [$group, , , $signer] = tick_review([tick_final_approval()], last: true);
    foreach ([TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed] as $index => $status) {
        Task::query()->create(['parent_id' => $group->id, 'position' => $index + 2, 'title' => $status->value, 'brief' => 'Other subtask.', 'status' => $status]);
    }
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskTurnPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 2, $group->project->taskCheckCommand())]);
});

it('reminds the reviewer when the approval of the last subtask has no pull request fields', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved')], last: true);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the review is complete. The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking. '.TaskTurnInstructions::reviewer(final: true, threadId: $group->reviewer_agent_thread_id))
        ->and($publishing->coverage->calls)->toBe(0)
        ->and($signer->messages)->toBe([]);
});

it('names each subtask the change list misses and does not commit', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([['Models']]);

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The pull request change list does not cover the subtask "Models".')
        ->and($signer->messages)->toBe([])
        ->and($publishing->publisher->bodies)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('retries opening the pull request without storing the approval twice', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->pr_url)->toBeNull()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->pushes)->toBe([$group->id]);

    app(TaskScheduler::class)->tick();
    expect($publishing->publisher->pushes)->toBe([$group->id]);

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->pushes)->toBe([$group->id, $group->id])
        ->and($publishing->publisher->bodies)->toHaveCount(2)
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling);
});

it('retries publication after Orbit commits and changes HEAD itself', function (): void {
    [$group, $task] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);
    $checks = app(TaskCheckRunner::class);
    if (! $checks instanceof FakeTaskCheckRunner) {
        throw new RuntimeException('The publication retry needs the fake check runner.');
    }
    $signer = new class($checks) implements TaskWorkspaceSigner
    {
        public int $commits = 0;

        public function __construct(private FakeTaskCheckRunner $checks) {}

        public function commit(Instance $instance, string $message): ?string
        {
            $this->commits++;
            $this->checks->head = str_repeat('c', 40);

            return $this->checks->head;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($signer->commits)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($publishing->publisher->bodies)->toHaveCount(1);

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
    app(TaskScheduler::class)->tick();

    expect($signer->commits)->toBe(1)
        ->and($publishing->publisher->bodies)->toHaveCount(2)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling);
});

it('refuses a reset to the pre-approval HEAD after Orbit committed', function (): void {
    [$group, $task] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);
    $checks = app(TaskCheckRunner::class);
    if (! $checks instanceof FakeTaskCheckRunner) {
        throw new RuntimeException('The publication retry needs the fake check runner.');
    }
    $signer = new class($checks) implements TaskWorkspaceSigner
    {
        public int $commits = 0;

        public function __construct(private FakeTaskCheckRunner $checks) {}

        public function commit(Instance $instance, string $message): ?string
        {
            $this->commits++;
            $this->checks->head = str_repeat('c', 40);

            return $this->checks->head;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($publishing->publisher->bodies)->toHaveCount(1);

    $checks->head = str_repeat('a', 40);
    app(TaskScheduler::class)->tick();

    expect($publishing->publisher->bodies)->toHaveCount(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($signer->commits)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();
});

it('counts a failed coverage answer as a communication failure', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    tick_publishing();
    app()->instance(TaskBriefCoverage::class, new class implements TaskBriefCoverage
    {
        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            throw new TaskSessionClassificationException('TypeSafe Jev request failed (ConnectionException).');
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($signer->messages)->toBe([])
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
});

/**
 * A running task whose idle implementer ended its turn with ready_for_review, with the given check readings.
 *
 * @param  list<TaskCheckReading>  $readings
 * @return array{Task, Task, FakeTaskCheckRunner, object}
 */
function tick_checking(array $readings): array
{
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $checks = new FakeTaskCheckRunner($readings);
    app()->instance(TaskCheckRunner::class, $checks);
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(CoderSettleNotifier::class, $notifier);

    return [$group, $group->tasks->sole(), $checks, $notifier];
}

it('waits while the check process runs and starts the reviewer only after it passes', function (): void {
    $finished = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "checks passed\n", 1790170874.25);
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running(), TaskCheckReading::running(), $finished]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $check = TaskCheck::query()->sole();
    expect($checks->starts)->toBe(1)
        ->and($check->status)->toBe(TaskCheckStatus::Running)
        ->and($check->task_comment_id)->toBe($task->comments()->sole()->id)
        ->and($check->pid)->toBe(4001)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Passed)
        ->and($check->fresh()?->exit_code)->toBe(0)
        ->and($check->fresh()?->finished_at?->getTimestamp())->toBe(1790170874)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('starts the check again when the tree changed during the run, then names the changed paths the second time', function (): void {
    $changed = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('c', 40), ['storage/check.cache'], "ok\n");
    [$group, $task, $checks] = tick_checking([$changed, $changed]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and(TaskCheck::query()->pluck('status')->all())->toBe([TaskCheckStatus::Changed, TaskCheckStatus::Running])
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The workspace changed while composer check ran, twice. Changed paths: storage/check.cache.')
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

it('starts a lost check again once, then asks for assistance', function (): void {
    [$group, $task, $checks, $notifier] = tick_checking([TaskCheckReading::lost(''), TaskCheckReading::lost('')]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toBe("Orbit's composer check stopped twice without a result.")
        ->and(TaskCheck::query()->pluck('status')->all())->toBe([TaskCheckStatus::Lost, TaskCheckStatus::Lost]);
});

it('reminds the implementer after an operator cancels the check', function (): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running()]);
    app(TaskScheduler::class)->tick();

    $cancelled = app(CancelTaskCheckAction::class)->execute($group, $task);
    app(TaskScheduler::class)->tick();

    expect($cancelled->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($checks->cancels)->toBe(1)
        ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain("An operator cancelled Orbit's composer check before it finished.")
        ->and($checks->starts)->toBe(1);
});

it('keeps a check running when the workspace cannot be read and counts a communication failure', function (): void {
    [$group, $task] = tick_checking([]);
    app(TaskScheduler::class)->tick();
    mock(TaskCheckRunner::class)->shouldReceive('read')->andThrow(new TaskCheckException('The task workspace could not be reached for the check.'));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

/**
 * Reports each thread in the given session status. Each thread's latest turn is "{thread}-turn".
 *
 * @param  array<string, string>  $states
 */
function tick_thread_states(array $states): AgentSnapshotReader
{
    return new class($states) implements AgentSnapshotReader
    {
        /** @param array<string, string> $states */
        public function __construct(public array $states) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => $this->states[$threadId] ?? 'idle'],
                'latestTurn' => ['id' => $threadId.'-turn', 'state' => 'completed'],
            ]];
        }
    };
}

describe('a thread that works outside the task phase', function (): void {
    it('collects a blocked implementer receipt and starts a consult while the shared reviewer works', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        app()->instance(AgentSnapshotReader::class, tick_thread_states(['implementer-thread' => 'done', 'reviewer-thread' => 'running']));
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Composer cannot reach the private package mirror.', 'Should I add the mirror credentials to auth.json?')]);
        $dispatcher = tick_dispatcher();
        tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done', 'reviewer' => 'running']);

        app(TaskScheduler::class)->tick();

        expect($task->comments()->sole()->getRawOriginal('type'))->toBe('blocked')
            ->and($receipts->cleared)->toHaveCount(1)
            ->and($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($group->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->consult_comment_id)->toBe($task->comments()->sole()->id)
            ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
            ->and(collect($dispatcher->commands)->contains(fn (array $command): bool => ($command['type'] ?? null) === 'create'))->toBeTrue();
    });

    it('leaves a running task alone while its implementer works', function (string $reviewerState): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        app()->instance(AgentSnapshotReader::class, tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => $reviewerState]));
        $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Stuck.', 'Which API version?')]);
        app()->instance(TaskTurnReceipts::class, $receipts);

        $decisions = app(TaskScheduler::class)->tick();

        expect($decisions)->toBe([])
            ->and($receipts->cleared)->toBe([])
            ->and($task->comments()->count())->toBe(0)
            ->and($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($dispatcher->commands)->toBe([]);
        Classification::assertNothingClassified();
    })->with(['idle', 'running']);

    it('moves a passing handoff to review but asks for the review only once the reviewer is idle', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->update(['task_id' => $task->id]);
        app(TaskExtensionState::class)->enable();
        $spawner = new class implements AgentSpawner
        {
            public int $reviews = 0;

            public function spawnReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnImplementer(Task $task): ?int
            {
                return null;
            }

            public function requestReview(Task $task): void
            {
                $this->reviews++;
            }
        };
        app()->instance(AgentSpawner::class, $spawner);
        $reader = tick_thread_states(['implementer-thread' => 'done', 'reviewer-thread' => 'running']);
        app()->instance(AgentSnapshotReader::class, $reader);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
            ->and($spawner->reviews)->toBe(0)
            ->and($task->fresh()?->review_notified_attempt)->toBeNull()
            ->and($task->fresh()?->communication_failures)->toBe(0)
            ->and(app(TaskTurnReceipts::class)->prepared)->toBe([]);

        $reader->states['reviewer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($spawner->reviews)->toBe(1)
            ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
            ->and($task->fresh()?->review_notified_turn_id)->toBe('reviewer-thread-turn')
            ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['reviewer:final']);
    });

    it('relays review findings only once the implementer is idle', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        $group->update(['status' => TaskGroupStatus::Reviewing]);
        $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn', ...tick_review_baseline()]);
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        $reader = tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => 'done']);
        app()->instance(AgentSnapshotReader::class, $reader);
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('changes_requested', 'Add the missing test.')]));

        app(TaskScheduler::class)->tick();

        expect($task->comments()->sole()->getRawOriginal('type'))->toBe('changes_requested')
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($task->fresh()?->communication_failures)->toBe(0)
            ->and($dispatcher->commands)->toBe([]);

        $reader->states['implementer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($dispatcher->commands)->toHaveCount(1)
            ->and($dispatcher->commands[0]['message']['text'])->toContain('Add the missing test.');
    });

    it('commits an approval only once the implementer stops changing the workspace', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked the models.')]);
        $reader = tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => 'done']);
        app()->instance(AgentSnapshotReader::class, $reader);
        app()->instance(AgentSpawner::class, new class implements AgentSpawner
        {
            public function spawnReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnImplementer(Task $task): ?int
            {
                return test_agent_thread($task->parent, 'implementer-'.$task->id, $task)->id;
            }

            public function requestReview(Task $task): void {}
        });

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe([])
            ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('approved')
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

        $reader->states['implementer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe(["Models\n\nChecked the models."])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
    });
});

/**
 * A running subtask with deliverables whose implementer hands off with the given confirmations, and a check that
 * passes with the given evidence.
 *
 * @param  list<array<string, string|bool>>  $deliverables
 * @param  array<string, string>  $confirmations
 * @param  array<string, mixed>|null  $evidence
 * @param  list<TaskCheckReading>|null  $readings  the check readings; null passes once with the evidence
 * @return array{0: Task, 1: Task, 2: FakeTaskCheckRunner, 3: CoderSettleNotifier, 4: FakeTaskTurnReceipts}
 */
function tick_deliverables(array $deliverables, array $confirmations, ?array $evidence, ?array $readings = null, ?array $receipts = null): array
{
    [$group, $task, $checks, $notifier] = tick_checking($readings ?? [FakeTaskCheckRunner::passed($evidence)]);
    $task->update(['deliverables' => $deliverables, 'subtask_start_commit' => str_repeat('5', 40)]);
    $fake = new FakeTaskTurnReceipts($receipts ?? [FakeTaskTurnReceipts::contents('ready_for_review', 'Done.', null, $confirmations)]);
    app()->instance(TaskTurnReceipts::class, $fake);

    return [$group, $task->fresh() ?? $task, $checks, $notifier, $fake];
}

/** @return list<array<string, string>> */
function tick_all_deliverables(): array
{
    return [
        ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/*.md', 'change' => 'modified'],
        ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
        ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => 'apps/web'],
        ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
    ];
}

/** @return array<string, string> */
function tick_all_confirmations(): array
{
    return ['reference-page' => 'Export section', 'export-test' => 'ExportTest', 'web-tests' => 'bun test passes', 'error-copy' => 'Named in each error'];
}

/**
 * Evidence where every deliverable of tick_all_deliverables() passes, changed by the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tick_evidence(array $overrides = []): array
{
    return [...[
        'start' => str_repeat('5', 40),
        'diff' => [
            ['status' => 'M', 'path' => 'docs/reference/tasks.md'],
            ['status' => 'A', 'path' => 'apps/gateway/tests/Feature/ExportTest.php'],
        ],
        'commands' => [
            'export-test' => ['exit_code' => 0, 'output' => ''],
            'web-tests' => ['exit_code' => 0, 'output' => "12 pass\n"],
        ],
    ], ...$overrides];
}

/** The implementer's reminder after two ticks: the handoff check starts, then passes. */
function tick_deliverable_reminder(): string
{
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    return app(AgentCommandDispatcher::class)->commands[0]['message']['text'] ?? '';
}

describe('subtask deliverables at handoff', function (): void {
    it('asks the check for the diff and commands, then starts the reviewer when every deliverable passes', function (): void {
        [$group, $task, $checks, , $receipts] = tick_deliverables(tick_all_deliverables(), tick_all_confirmations(), tick_evidence());

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($checks->deliverables)->toBe([[
            'start' => str_repeat('5', 40),
            'commands' => [
                ['id' => 'export-test', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
                ['id' => 'web-tests', 'command' => 'bun test', 'directory' => 'apps/web'],
            ],
        ]])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($task->comments()->sole()->deliverables)->toBe(tick_all_confirmations())
            ->and(TaskCheck::query()->sole()->deliverable_evidence)->toBe(tick_evidence())
            ->and($receipts->turnDeliverables)->toBe([['reference-page', 'export-test', 'web-tests', 'error-copy']]);
    });

    it('skips deliverables for a subtask without any', function (): void {
        [$group, $task, $checks] = tick_deliverables([], [], null);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($checks->deliverables)->toBe([null])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
    });

    it('returns a failing file deliverable to the implementer with the reason', function (array $diff, string $change, string $reason): void {
        $deliverable = ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/*.md', 'change' => $change];
        [$group, $task] = tick_deliverables([$deliverable], ['reference-page' => 'Done'], ['start' => str_repeat('5', 40), 'diff' => $diff, 'commands' => []]);

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("Orbit could not verify these deliverables:\n- reference-page (file): {$reason}")
            ->and($reminder)->toContain('--deliverable=ID=evidence for each deliverable of this subtask (reference-page)');
    })->with([
        'a missing path' => [[['status' => 'M', 'path' => 'docs/guides/tasks.md']], 'any', "no path in the subtask's diff matches docs/reference/*.md."],
        'a nested path the glob does not reach' => [[['status' => 'M', 'path' => 'docs/reference/cli/tasks.md']], 'modified', "no path in the subtask's diff matches docs/reference/*.md."],
        'a modified file that should be created' => [[['status' => 'M', 'path' => 'docs/reference/tasks.md']], 'created', 'the diff modifies docs/reference/tasks.md, but the deliverable needs docs/reference/*.md created.'],
        'a created file that should be modified' => [[['status' => 'A', 'path' => 'docs/reference/export.md']], 'modified', 'the diff adds docs/reference/export.md, but the deliverable needs docs/reference/*.md modified.'],
        'a deleted file' => [[['status' => 'D', 'path' => 'docs/reference/tasks.md']], 'any', 'the diff deletes docs/reference/tasks.md, but the deliverable needs docs/reference/*.md modified.'],
    ]);

    it('returns a failing command deliverable to the implementer with the reason', function (): void {
        $deliverable = ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'];
        [$group, $task] = tick_deliverables([$deliverable], ['export-test' => 'ExportTest'], tick_evidence(['commands' => []]));

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("- export-test (command): Orbit's check did not run `vendor/bin/pest tests/Feature/ExportTest.php` in apps/gateway.");
    });

    it('fails every mechanical deliverable when the check recorded no evidence', function (): void {
        [$group, $task] = tick_deliverables(tick_all_deliverables(), tick_all_confirmations(), null);

        expect(tick_deliverable_reminder())->toContain("Orbit's check recorded no evidence for the deliverables reference-page, export-test, web-tests.");
    });

    it('refuses a hand-written receipt that does not confirm every deliverable before the check runs', function (): void {
        [$group, $task, $checks] = tick_deliverables(tick_all_deliverables(), ['reference-page' => 'Export section'], tick_evidence());

        app(TaskScheduler::class)->tick();

        expect($checks->starts)->toBe(0)
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The turn receipt does not confirm the deliverables export-test, web-tests, error-copy. Pass --deliverable=ID=evidence for each one.');
    });

    it('asks for assistance when a deliverable still fails after the reminder', function (): void {
        $failing = FakeTaskCheckRunner::passed(tick_evidence(['commands' => [
            'export-test' => ['exit_code' => 0, 'output' => ''],
            'web-tests' => ['exit_code' => 2, 'output' => "boom\n"],
        ]]));
        [$group, $task, $checks, $notifier] = tick_deliverables(
            tick_all_deliverables(),
            tick_all_confirmations(),
            null,
            [$failing, $failing],
            [FakeTaskTurnReceipts::contents('ready_for_review', 'Done.', null, tick_all_confirmations()), null, FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed it.', null, tick_all_confirmations()), null],
        );

        tick_deliverable_reminder();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->assistance_requested)->toBeTrue()
            ->and($notifier->reason)->toStartWith("Checks still failed after the reminder. Orbit could not verify these deliverables:\n- web-tests (command): `bun test` in apps/web exited with 2.")
            ->and($checks->starts)->toBe(2)
            ->and(app(AgentCommandDispatcher::class)->commands)->toHaveCount(1);
    });

    it('reminds a reviewer whose approval does not confirm each review deliverable', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked.', null, ['reference-page' => 'Read it'])]);
        $task->update(['deliverables' => tick_all_deliverables()]);

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe([])
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The turn receipt does not confirm the deliverables error-copy.')
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The approval must confirm each review deliverable (error-copy) with --deliverable=ID=evidence');
    });

    it('commits an approval that confirms each review deliverable', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked.', null, ['error-copy' => 'Each error names the subtask'])]);
        $task->update(['deliverables' => tick_all_deliverables()]);

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe(["Models\n\nChecked."])
            ->and($task->comments()->sole()->deliverables)->toBe(['error-copy' => 'Each error names the subtask']);
    });

    it('asks the check to prove a fails_on_base command and returns it when base also passes', function (): void {
        $deliverable = [
            'id' => 'layout-repro', 'type' => 'command', 'description' => 'The layout fails before the fix',
            'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php', 'directory' => 'apps/gateway',
            'fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
        ];
        $evidence = tick_evidence(['commands' => [
            'layout-repro' => ['base_started' => true, 'base_exit_code' => 0, 'base_output' => '', 'exit_code' => 0, 'output' => ''],
        ]]);
        [$group, $task, $checks] = tick_deliverables([$deliverable], ['layout-repro' => 'The command is in the Pest file'], $evidence);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($checks->deliverables)->toBe([[
                'start' => str_repeat('5', 40),
                'commands' => [[
                    'id' => 'layout-repro',
                    'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php',
                    'directory' => 'apps/gateway',
                    'fails_on_base' => true,
                    'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
                ]],
            ]])
            ->and(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('also exited 0 on the start commit');
    });

    it('asks for assistance when a command overlay path is invalid, without reminding the implementer', function (): void {
        $reason = 'Deliverable sweep-test names invalid overlay path missing.sh.';
        $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $reason."\n", failedStep: 'invalid_deliverable');
        [$group, $task, $checks, $notifier] = tick_deliverables(
            [[
                'id' => 'sweep-test',
                'type' => 'command',
                'description' => 'Repro the sweep',
                'command' => 'vendor/bin/pest tests/Feature/SweepTest.php',
                'directory' => 'apps/gateway',
                'fails_on_base' => true,
                'paths' => ['missing.sh'],
            ]],
            ['sweep-test' => 'The check names the missing path'],
            null,
            [$failed],
        );

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_reason)->toBe($reason)
            ->and($task->fresh()?->assistance_reason)->toBe($reason)
            ->and($notifier->reason)->toBe($reason)
            ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
            ->and($checks->starts)->toBe(1)
            ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed)
            ->and(TaskCheck::query()->sole()->failed_step)->toBe('invalid_deliverable');
    });

    it('asks for assistance for an invalid deliverable even when the tree changed', function (): void {
        $reason = 'Deliverable sweep-test names invalid overlay path missing.sh.';
        $failed = TaskCheckReading::finished(1, str_repeat('d', 40), str_repeat('c', 40), ['app'], $reason."\n", failedStep: 'invalid_deliverable');
        [$group, $task, $checks, $notifier] = tick_deliverables(
            [[
                'id' => 'sweep-test',
                'type' => 'command',
                'description' => 'Repro the sweep',
                'command' => 'vendor/bin/pest tests/Feature/SweepTest.php',
                'directory' => 'apps/gateway',
                'fails_on_base' => true,
                'paths' => ['missing.sh'],
            ]],
            ['sweep-test' => 'The check names the missing path'],
            null,
            [$failed],
        );

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_reason)->toBe($reason)
            ->and($task->fresh()?->assistance_reason)->toBe($reason)
            ->and($notifier->reason)->toBe($reason)
            ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
            ->and($checks->starts)->toBe(1)
            ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed)
            ->and(TaskCheck::query()->sole()->failed_step)->toBe('invalid_deliverable')
            ->and(TaskCheck::query()->sole()->changed_paths)->toBe(['app']);
    });
});

it('project baseline setup only', function (): void {
    $spawner = new class implements AgentSpawner
    {
        /** @var list<string> */
        public array $events = [];

        public function spawnReviewer(Task $task): ?int
        {
            $this->events[] = 'reviewer';

            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events[] = 'implementer:'.$task->position;

            return test_agent_thread($task->parent, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }
    };
    app()->instance(AgentSpawner::class, $spawner);
    app(TaskExtensionState::class)->enable();

    $composer = tick_baseline_group('baseline-composer', 'composer check', [
        ['name' => 'Warm cache', 'command' => 'echo warm', 'timeout_seconds' => 30, 'position' => 2],
        ['name' => 'Install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 900, 'position' => 1],
    ], '10.51.0.1');
    ProjectLifecycleStep::query()->create([
        'project_id' => $composer->project_id,
        'phase' => 'teardown',
        'name' => 'Remove bridge',
        'command' => 'echo teardown',
        'timeout_seconds' => 60,
        'position' => 1,
    ]);
    $javascript = tick_baseline_group('baseline-javascript', 'vp run check', [
        ['name' => 'Install packages', 'command' => 'vp install --frozen-lockfile', 'timeout_seconds' => 120, 'position' => 1],
    ], '10.51.0.2');
    $unset = tick_baseline_group('baseline-unset-check', null, [
        ['name' => 'Prepare', 'command' => 'echo prepare', 'timeout_seconds' => 45, 'position' => 1],
    ], '10.51.0.3');
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->tick();

    expect($checks->commands)->toBe(['composer check', 'vp run check', null])
        ->and($checks->setups)->toBe([
            [
                ['name' => 'Install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 900],
                ['name' => 'Warm cache', 'command' => 'echo warm', 'timeout_seconds' => 30],
            ],
            [
                ['name' => 'Install packages', 'command' => 'vp install --frozen-lockfile', 'timeout_seconds' => 120],
            ],
            [
                ['name' => 'Prepare', 'command' => 'echo prepare', 'timeout_seconds' => 45],
            ],
        ])
        ->and($spawner->events)->toBe([]);

    $composer->update(['status' => TaskGroupStatus::Completed]);
    $javascript->update(['status' => TaskGroupStatus::Completed]);

    app(TaskScheduler::class)->tick();

    expect($unset->fresh()?->assistance_requested)->toBeFalse()
        ->and($unset->tasks()->value('implementer_agent_thread_id'))->not->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);

    $unset->update(['status' => TaskGroupStatus::Completed]);
    $failedSetup = tick_baseline_group('baseline-setup-failed', 'composer check', [
        ['name' => 'Install', 'command' => 'composer install --no-interaction', 'timeout_seconds' => 600, 'position' => 1],
    ], '10.51.0.4');
    $setupOutput = "setup blew up\n";
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(7, str_repeat('a', 40), str_repeat('b', 40), [], $setupOutput, null, str_repeat('b', 40), 'Install'),
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $failedTaskId = $failedSetup->tasks()->value('id');
    expect($failedSetup->fresh()?->assistance_requested)->toBeTrue()
        ->and($failedSetup->fresh()?->assistance_reason)->toBe('The Project setup step "Install" failed with exit code 7 on a fresh checkout of task-'.$failedSetup->id.', before any agent started. Fix the setup or the branch, then post a resolution on this subtask to retry the baseline. The task\'s check shows the output.')
        ->and(TaskCheck::query()->where('task_id', $failedTaskId)->sole()->output)->toBe($setupOutput)
        ->and($failedSetup->tasks()->value('implementer_agent_thread_id'))->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);

    $failedSetup->update(['status' => TaskGroupStatus::Completed]);
    $ordinary = tick_baseline_group('baseline-ordinary-failure', 'composer check', [], '10.51.0.5');
    $ordinaryOutput = "sh: 1: vendor/bin/pest: not found\nsh: 1: node_modules/.bin/vite: not found\n";
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $ordinaryOutput, null, str_repeat('b', 40), null),
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reason = $ordinary->fresh()?->assistance_reason;
    expect($ordinary->fresh()?->assistance_requested)->toBeTrue()
        ->and($reason)->toBe('The Project baseline check failed with exit code 1 on a fresh checkout of task-'.$ordinary->id.', before any agent started. Fix the configured check or the branch, then post a resolution on this subtask to retry the baseline. The task\'s check shows the output.')
        ->and($reason)->not->toContain('Project dependencies appear to be missing')
        ->and($reason)->not->toContain('Composer dependency installation failed')
        ->and($reason)->not->toContain('JavaScript dependency installation failed')
        ->and(TaskCheck::query()->where('task_id', $ordinary->tasks()->value('id'))->sole()->output)->toBe($ordinaryOutput)
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('retries a failed baseline on resolution at the current default branch tip without recreating the group', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-resolution', 'composer check', [], '10.51.0.6');
    $agents = tick_running_agents();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $failed = TaskCheck::query()->where('task_id', $task->id)->sole();
    expect($task->assistance_requested)->toBeTrue()
        ->and($task->assistance_kind)->toBe(AssistanceKind::Failure);
    $task->update(['subtask_start_commit' => str_repeat('a', 40)]);
    // The remote default branch advanced after the first baseline failed.
    $tip = str_repeat('c', 40);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->once()->ordered();
    $fetcher->shouldReceive('resetToDefault')->once()->ordered()->andReturn($tip);

    $resolution = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'The default branch check is fixed. Retry.', 'author' => 'operator',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'status' => 'running', 'subtask_start_commit' => $tip,
        'assistance_requested' => false, 'assistance_reason' => null,
        'assistance_kind' => null, 'assistance_question' => null,
        'resolution_delivered_comment_id' => $resolution->id, 'implementer_agent_thread_id' => null,
    ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $group->id, 'status' => 'running', 'assistance_requested' => false,
        'assistance_kind' => null, 'assistance_question' => null, 'assistance_reason' => null,
    ]);
    expect($runner->starts)->toBe(1);
    expect($failed->fresh()?->output)->toBe("baseline failed\n");
    expect($failed->fresh()?->status)->toBe(TaskCheckStatus::Failed);

    app(TaskScheduler::class)->tick();

    expect($runner->starts)->toBe(2);
    expect(TaskCheck::query()->where('task_id', $task->id)->count())->toBe(2);
    $this->assertDatabaseHas('task_checks', ['task_id' => $task->id, 'kind' => 'baseline', 'status' => 'running']);
    expect($agents->spawned)->toBe([]);

    app()->instance(TaskBaseBranchFetcher::class, $agents);
    app(TaskScheduler::class)->tick();

    expect($agents->spawned)->toBe([$task->id]);
    expect($task->fresh()?->subtask_start_commit)->toBe($tip);
});

it('keeps baseline assistance and evidence when retry preparation fails', function (string $step): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-retry-failed', 'composer check', [], '10.51.0.7');
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $task->update(['subtask_start_commit' => str_repeat('a', 40)]);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    if ($step === 'fetch') {
        $fetcher->shouldReceive('fetchForTurn')->twice()->andThrow(new TaskPullRequestException('Fetch failed.'));
        $fetcher->shouldNotReceive('resetToDefault');
    } else {
        $fetcher->shouldReceive('fetchForTurn')->twice();
        $fetcher->shouldReceive('resetToDefault')->twice()->andThrow(new TaskPullRequestException('Reset failed.'));
    }

    $resolution = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'assistance_requested' => true, 'subtask_start_commit' => str_repeat('a', 40),
        'resolution_delivered_comment_id' => $resolution->id, 'implementer_agent_thread_id' => null,
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => true]);
    expect(TaskCheck::query()->where('task_id', $task->id)->sole()->output)->toBe("baseline failed\n");
    expect($runner->starts)->toBe(1);

    // A pending failure resolution must not reset the workspace after the operator asks for direction.
    $record = $step === 'fetch' ? $task : $group;
    TaskAssistance::apply($record, AssistanceKind::Direction, 'Which setup should this Project use?', 'Which setup should this Project use?');
    expect(app(RetryTaskBaselineAction::class)->recover($task))->toBeFalse();
    $this->assertDatabaseHas('tasks', [
        'id' => $record->id, 'assistance_requested' => true, 'assistance_kind' => 'direction',
        'assistance_question' => 'Which setup should this Project use?',
    ]);
})->with(['fetch', 'reset']);

it('recovers a baseline retry after an interrupted reset transition without another resolution', function (string $failure): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-recovery', 'composer check', [], '10.51.0.9');
    $agents = tick_running_agents();
    $runner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $head = str_repeat('a', 40);
    $tip = str_repeat('c', 40);
    $task->update(['subtask_start_commit' => $head]);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldReceive('fetchForTurn')->twice();
    $resets = 0;
    $fetcher->shouldReceive('resetToDefault')->twice()->andReturnUsing(function () use (&$head, $tip, &$resets, $failure): string {
        $head = $tip;
        $resets++;
        if ($resets === 1 && $failure === 'lost response') {
            throw new TaskPullRequestException('Reset succeeded but its reply was lost.');
        }

        return $head;
    });
    if ($failure === 'bookkeeping rollback') {
        DB::statement("CREATE TRIGGER fail_baseline_retry_bookkeeping BEFORE UPDATE OF subtask_start_commit ON tasks WHEN NEW.subtask_start_commit = '$tip' BEGIN SELECT RAISE(ABORT, 'baseline retry bookkeeping failed'); END");
        expect(fn () => app(StoreTaskCommentAction::class)->execute($task, [
            'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
        ]))->toThrow(QueryException::class, 'baseline retry bookkeeping failed');
        DB::statement('DROP TRIGGER fail_baseline_retry_bookkeeping');
    } else {
        app(StoreTaskCommentAction::class)->execute($task, [
            'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
        ]);
    }
    $resolution = TaskComment::query()->where('task_id', $task->id)->where('type', 'resolution')->sole();
    expect($head)->toBe($tip);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'subtask_start_commit' => str_repeat('a', 40),
        'assistance_requested' => true, 'resolution_delivered_comment_id' => $resolution->id]);
    expect($runner->starts)->toBe(1);

    // A new scheduler instance represents the next Gateway process. No new resolution is posted.
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'subtask_start_commit' => $head, 'assistance_requested' => false]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => false]);
    expect($runner->starts)->toBe(2);
    expect($agents->spawned)->toBe([]);
    app()->instance(TaskBaseBranchFetcher::class, $agents);
    app(TaskScheduler::class)->tick();

    expect($resets)->toBe(2);
    expect($runner->starts)->toBe(2);
    expect(TaskCheck::query()->where('task_id', $task->id)->count())->toBe(2);
    expect($agents->spawned)->toBe([$task->id]);
    expect(TaskComment::query()->where('task_id', $task->id)->where('type', 'resolution')->count())->toBe(1);
})->with(['lost response', 'bookkeeping rollback']);

it('never resets a failed baseline workspace after an implementer has started elsewhere in the group', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group('baseline-touched', 'composer check', [], '10.51.0.8');
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "baseline failed\n"),
    ]));
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    $other = Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Earlier work', 'brief' => 'Done.', 'status' => TaskStatus::Completed,
    ]);
    test_agent_thread($group, 'earlier-implementer', $other);
    $fetcher = mock(TaskBaseBranchFetcher::class);
    $fetcher->shouldNotReceive('fetchForTurn');
    $fetcher->shouldNotReceive('resetToDefault');

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Retry the baseline.', 'author' => 'operator',
    ]);

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assistance_requested' => true, 'resolution_delivered_comment_id' => null]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => true]);
});

/**
 * A fresh running task whose baseline has not started.
 *
 * @param  list<array{name: string, command: string, timeout_seconds: int, position: int}>  $steps
 */
function tick_baseline_group(string $slug, ?string $taskCheck, array $steps, string $ip): Task
{
    $project = Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
        'task_check' => $taskCheck,
    ]);
    $node = Node::query()->create([
        'name' => $slug.'-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $slug,
        'checkout_path' => '/tmp/tasks-'.$slug,
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => $slug,
        'brief' => 'Baseline setup only.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'First subtask',
        'status' => TaskStatus::Running,
    ]);
    foreach ($steps as $step) {
        ProjectLifecycleStep::query()->create([
            'project_id' => $project->id,
            'phase' => 'setup',
            'name' => $step['name'],
            'command' => $step['command'],
            'timeout_seconds' => $step['timeout_seconds'],
            'position' => $step['position'],
        ]);
    }

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

it('updates a behind pull request without creating an agent fixup and waits for the new head', function (TaskGroupStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => $status]);
    $agents = tick_running_agents();
    // Captured from the disposable GitHub proof PR #973 (2026-10-07).
    tick_watch_pulls([tick_open_pull(['mergeable_state' => 'behind']), tick_open_pull(['mergeable_state' => 'behind'])], ['abc123' => []], 202, 'Updating pull request branch.');

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe($status)
        ->and($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(0)
        ->and($agents->spawned)->toBe([]);
    $updates = Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/update-branch'));
    expect($updates)->toHaveCount(1);
    expect($updates->first()[0]->method())->toBe('PUT')
        ->and($updates->first()[0]->data())->toBe(['expected_head_sha' => 'abc123']);
})->with([TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview]);

it('waits visibly without a fixup when a branch update is refused without a merge conflict', function (int $status, string $message): void {
    $group = tick_settling_group();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []], $status, $message);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(0)
        ->and($group->fresh()->assistance_reason)->toContain('GitHub could not update');
})->with([
    'stale head' => [422, 'Expected head sha did not match'],
    'permission' => [403, 'Resource not accessible by integration'],
    'outage' => [503, 'Service Unavailable'],
]);

it('scopes only nonfinal Orbit handoff tests to the subtask start', function (bool $later, bool $orbit): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running()]);
    $group->project->update(['slug' => $orbit ? 'orbit' : 'other']);
    $task->update(['subtask_start_commit' => str_repeat('a', 40)]);
    if ($later) {
        Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Later', 'brief' => 'Next.', 'status' => TaskStatus::Todo]);
    }

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(1)
        ->and($checks->deliverables[0]['test_base'] ?? null)->toBe($later && $orbit ? str_repeat('a', 40) : null);
})->with([[true, true], [false, true], [true, false]]);
