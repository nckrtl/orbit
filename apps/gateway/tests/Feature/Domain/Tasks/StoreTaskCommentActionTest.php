<?php

declare(strict_types=1);

use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\NullTaskReviewDiff;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\Tasks\TaskWorkspaceMetadata;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\NullAgentSnapshotReader;

/** A blocked task in the given status, with an implementer and a reviewer thread. */
function blocked_task(TaskStatus $status): Task
{
    $project = Project::query()->create([
        'name' => 'blocked', 'slug' => 'blocked',
        'repository_url' => 'git@example.test:blocked.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'blocked-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.190', 'wireguard_ip' => '10.44.0.190',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-9',
        'checkout_path' => '/tmp/task-9', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Blocked', 'brief' => 'Unblock it.',
        'status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running,
        'assistance_requested' => true, 'assistance_reason' => 'Blocked.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Only', 'brief' => 'One',
        'status' => $status, 'assistance_requested' => true, 'assistance_reason' => 'Blocked.',
        'completion_attempt' => 2, 'review_attempt' => 3, 'review_notified_attempt' => 3,
    ]);
    test_link_agent_threads($group->fresh(['tasks']) ?? $group, reviewer: 'reviewer-thread', implementer: 'implementer-thread');
    if ($status === TaskStatus::Reviewing) {
        AgentThread::query()->where('task_group_id', $group->id)->where('external_id', 'reviewer-thread')->update(['task_id' => $task->id]);
    }

    return $task->fresh() ?? $task;
}

/** Binds a T3 driver whose dispatcher records the thread of every started turn. */
function recording_t3_turns(?Closure $beforeAccept = null): object
{
    $dispatcher = new class($beforeAccept) implements AgentCommandDispatcher
    {
        /** @var list<string> */
        public array $threads = [];

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function __construct(private ?Closure $beforeAccept) {}

        public function dispatch(Node $node, array $command): array
        {
            ($this->beforeAccept ?? static function (): void {})();
            $this->commands[] = $command;
            $this->threads[] = (string) ($command['threadId'] ?? '');

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher));

    return $dispatcher;
}

it('resumes a deliverable correction on the same implementer with refreshed turn deliverables', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $contract = [
        ['id' => 'corrected', 'type' => 'file', 'description' => 'Same description.', 'path' => 'tests/CorrectedTest.php', 'change' => 'created'],
        ['id' => 'repro', 'type' => 'command', 'description' => 'Same description.', 'command' => 'vendor/bin/pest --filter=corrected', 'directory' => 'apps/gateway', 'fails_on_base' => true, 'paths' => ['apps/gateway/tests/CorrectedTest.php']],
    ];
    $task->update(['deliverable_correction_check_id' => 1, 'deliverables' => $contract]);
    $receipts = new FakeTaskTurnReceipts;
    app()->instance(TaskTurnReceipts::class, $receipts);
    $turns = recording_t3_turns();
    $threadId = $task->implementer_agent_thread_id;

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'The invalid deliverable is corrected. Resume handoff.', 'author' => 'operator',
    ]);

    expect($receipts->prepared)->toBe(['implementer'])
        ->and($receipts->turnDeliverables)->toBe([['corrected', 'repro']])
        ->and($turns->threads)->toBe(['implementer-thread']);
    $message = $turns->commands[0]['message']['text'];
    $json = explode("```json\n", $message)[1];
    expect(json_decode(explode("\n```", $json)[0], true))->toBe($contract);
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->parent->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->completion_attempt)->toBe(3)
        ->and($task->fresh()?->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->implementer_agent_thread_id)->toBe($threadId)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

it('replays a deliverable correction safely after prepare failures, lost replies, and a delivery commit crash', function (string $failure): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['deliverable_correction_check_id' => 1, 'subtask_start_commit' => str_repeat('b', 40), 'deliverables' => [['id' => 'corrected', 'type' => 'review', 'description' => 'Corrected.']]]);
    $checkout = sys_get_temp_dir().'/orbit-correction-replay-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($checkout.'/.git/orbit');
    $task->parent->taskable->update(['checkout_path' => $checkout]);
    $prepared = [];
    $injectPrepare = true;
    $receipts = Mockery::mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('prepare')->andReturnUsing(function (Instance $instance, TaskThreadRole $role, bool $final, array $deliverables, ?int $threadId, ?TaskTurnMode $mode) use (&$prepared, &$injectPrepare, $failure, $checkout): void {
        $prepared[] = $mode?->deliveryKey;
        if ($injectPrepare && $failure === 'prepare-before') {
            $injectPrepare = false;
            throw new TaskTurnReceiptException('Injected failure before prepare.');
        }
        $payload = ['script' => base64_encode((string) file_get_contents(resource_path('tasks/turn'))), 'turn' => json_encode(['role' => $role->value, 'thread' => $threadId, 'delivery_key' => $mode?->deliveryKey, 'deliverables' => [['id' => 'corrected', 'type' => 'review', 'description' => 'Corrected.']]], JSON_THROW_ON_ERROR), 'context' => null];
        $program = "checkout=\$1\n".TaskWorkspaceMetadata::bashPreamble().TaskWorkspaceMetadata::operation('turn', $payload);
        (new Process(['bash', '-seu', '--', $checkout], input: $program))->mustRun();
        if ($injectPrepare && $failure === 'prepare-after') {
            $injectPrepare = false;
            throw new TaskTurnReceiptException('Injected lost prepare reply.');
        }
    });
    app()->instance(TaskTurnReceipts::class, $receipts);
    $dispatcher = new class($checkout, $task->implementer_agent_thread_id, $failure) implements AgentCommandDispatcher
    {
        /** @var list<string> */
        public array $calls = [];

        /** @var array<string, bool> */
        public array $accepted = [];

        public bool $loseReplies = true;

        public function __construct(private string $checkout, private ?int $threadId, private string $failure) {}

        public function dispatch(Node $node, array $command): array
        {
            $key = (string) $command['commandId'];
            $this->calls[] = $key;
            if (! isset($this->accepted[$key])) {
                $this->accepted[$key] = true;
                (new Process([$this->checkout.'/.git/orbit/turn', '--thread='.$this->threadId, '--outcome=ready_for_review', '--summary=Resumed work done.', '--deliverable=corrected=Reviewed'], cwd: $this->checkout))->mustRun();
            }
            if ($this->loseReplies && $this->failure === 'send-replies-lost') {
                throw new AgentDriverException('Accepted remotely, but both replies lost.');
            }

            return ['sequence' => 1, 'thread_id' => (string) $command['threadId']];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: new NullAgentSnapshotReader));
    $injectCommit = $failure === 'commit-crash';
    DB::beforeExecuting(function (string $sql) use (&$injectCommit): void {
        if ($injectCommit && str_starts_with($sql, 'update') && str_contains($sql, 'completion_attempt') && str_contains($sql, 'deliverable_correction_resume')) {
            $injectCommit = false;
            throw new RuntimeException('Injected crash after accepted send, before delivery commit.');
        }
    });

    try {
        if ($failure === 'commit-crash') {
            expect(fn () => app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Resume the corrected contract.', 'author' => 'operator']))->toThrow(RuntimeException::class, 'Injected crash');
        } else {
            app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Resume the corrected contract.', 'author' => 'operator']);
        }
        $pending = $task->fresh()?->deliverable_correction_resume;
        expect($pending['state'])->toBe('pending');
        expect($task->fresh()?->assistance_requested)->toBeTrue();
        $receipt = file_exists($checkout.'/.git/orbit/receipt.json') ? file_get_contents($checkout.'/.git/orbit/receipt.json') : null;
        $dispatcher->loseReplies = false;
        app(TaskExtensionState::class)->enable();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->deliverable_correction_resume['state'])->toBe('delivered')
            ->and($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->completion_attempt)->toBe(3);
        expect(array_unique($prepared))->toBe([$pending['key']]);
        expect(array_keys($dispatcher->accepted))->toBe([$pending['key']]);
        expect(array_unique($dispatcher->calls))->toBe([$pending['key']]);
        if ($receipt !== null) {
            expect(file_get_contents($checkout.'/.git/orbit/receipt.json'))->toBe($receipt);
        }
        $calls = count($dispatcher->calls);
        app(ResumeDeliverableCorrectionAction::class)->execute($task->fresh() ?? $task);
        expect(count($dispatcher->calls))->toBe($calls);
    } finally {
        File::deleteDirectory($checkout);
    }
})->with(['prepare-before', 'prepare-after', 'send-replies-lost', 'commit-crash']);

it('preserves intervening direction instead of reserving or retrying a correction', function (bool $pending): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['deliverable_correction_check_id' => 1, 'deliverables' => [['id' => 'corrected', 'type' => 'review', 'description' => 'Corrected.']]]);
    $receipts = new FakeTaskTurnReceipts;
    app()->instance(TaskTurnReceipts::class, $receipts);
    $turns = recording_t3_turns();
    if ($pending) {
        $comment = TaskComment::query()->create(['task_group_id' => $task->parent_id, 'task_id' => $task->id, 'type' => 'resolution', 'body' => 'Corrected.', 'author' => 'operator', 'posted_at' => now()]);
        app(ResumeDeliverableCorrectionAction::class)->reserve($task, $comment);
    }
    app(StoreTaskCommentAction::class)->execute($task, ['type' => 'assistance_requested', 'body' => 'Which approach is safe?', 'author' => 'operator']);
    $resume = $task->fresh()?->deliverable_correction_resume;
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    app(ResumeDeliverableCorrectionAction::class)->execute($task);

    expect($turns->threads)->toBe([]);
    expect($receipts->prepared)->toBe([]);
    expect($task->fresh()?->deliverable_correction_resume)->toBe($resume);
    expect($task->fresh()?->completion_attempt)->toBe(2);
    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction);
    expect($task->fresh()?->parent->assistance_question)->toBe('Which approach is safe?');
    AgentThread::query()->where('external_id', 'reviewer-thread')->update(['task_id' => $task->id]);
    app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Ask the reviewer about the safe approach.', 'author' => 'operator']);
    expect($task->fresh()?->direction_relay_comment_id)->not->toBeNull();
    app(ResumeDeliverableCorrectionAction::class)->execute($task);
    expect($turns->threads)->toBe(['reviewer-thread']);
    expect($task->fresh()?->deliverable_correction_resume)->toBe($resume);
    expect($task->fresh()?->completion_attempt)->toBe(2);
})->with(['before reservation' => false, 'pending retry' => true]);

it('does not clear group-only direction through the ordinary resolution fallback after correction', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['deliverable_correction_check_id' => 1]);
    TaskAssistance::apply($task->parent, AssistanceKind::Direction, 'New group direction.', 'New group direction.', replaceDirection: true);
    $turns = recording_t3_turns();

    app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Resume correction.', 'author' => 'operator']);
    app(ResumeDeliverableCorrectionAction::class)->execute($task);

    expect($turns->threads)->toBe([]);
    expect($task->fresh()?->deliverable_correction_resume)->toBeNull();
    expect($task->fresh()?->completion_attempt)->toBe(2);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->parent->assistance_question)->toBe('New group direction.');
});

it('keeps a direction arriving during correction acceptance and suppresses repeat delivery', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['deliverable_correction_check_id' => 1]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    $turns = recording_t3_turns(function () use ($task): void {
        app(StoreTaskCommentAction::class)->execute($task, ['type' => 'assistance_requested', 'body' => 'New direction during send.', 'author' => 'operator']);
    });

    app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Corrected.', 'author' => 'operator']);
    app(ResumeDeliverableCorrectionAction::class)->execute($task);

    expect($turns->threads)->toBe(['implementer-thread']);
    expect($task->fresh()?->deliverable_correction_resume['state'])->toBe('delivered');
    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction);
    expect($task->fresh()?->parent->assistance_question)->toBe('New direction during send.');
    expect(TaskQuestion::query()->where('subtask_id', $task->id)->sole()->resolution_comment_id)->toBeNull();
});

it('retains the authenticated correction resolution caller and request through scheduler retry', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['deliverable_correction_check_id' => 1]);
    $actor = $task->parent->taskable->node;
    $this->markAsGateway($actor);
    $this->withServerVariables(['REMOTE_ADDR' => $actor->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $receipts = Mockery::mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('prepare')->once()->andThrow(new TaskTurnReceiptException('Lost reply.'));
    app()->instance(TaskTurnReceipts::class, $receipts);
    $turns = recording_t3_turns();
    $requestId = 'bb94949e-6a5f-419c-872a-14fa89f5cc61';

    $this->withHeader('X-Orbit-Request-Id', $requestId)->postJson("/api/v1/task-groups/{$task->parent_id}/tasks/{$task->id}/comments", ['type' => 'resolution', 'body' => 'Corrected.', 'author' => 'not-the-authenticated-actor'])->assertCreated();
    $resume = $task->fresh()?->deliverable_correction_resume;
    expect($resume['caller_node_id'])->toBe($actor->id);
    expect($resume['request_id'])->toBe($requestId);
    app()->instance(AgentSnapshotReader::class, new NullAgentSnapshotReader);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    app(TaskScheduler::class)->tick();
    app(ResumeDeliverableCorrectionAction::class)->execute($task);

    $audit = Activity::query()->where('description', 'deliverable correction resumed')->sole();
    expect($audit->getRawOriginal('caller_node_id'))->toBe($actor->id);
    expect($audit->caller_ip)->toBe($actor->wireguard_ip);
    expect($audit->request_id)->toBe($requestId);
    expect($audit->properties?->get('comment_id'))->toBe($resume['comment_id']);
    expect($turns->threads)->toBe(['implementer-thread']);
});

it('relays a direction resolution to the reviewer of a running subtask', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['assistance_kind' => AssistanceKind::Direction, 'assistance_question' => 'Which mirror?']);
    AgentThread::query()->where('task_group_id', $task->parent_id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    TaskQuestion::query()->create([
        'task_id' => $task->parent_id, 'subtask_id' => $task->id, 'attempt' => 2, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    $turns = recording_t3_turns();

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the public mirror.', 'author' => 'operator',
    ]);

    expect($turns->threads)->toBe(['reviewer-thread'])
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->direction_relay_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->completion_attempt)->toBe(2)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Escalated)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id);
});

it('rolls back a direction delivery that fails before the question is linked and accepts a replay', function (TaskStatus $status): void {
    $task = blocked_task($status);
    $task->update(['assistance_kind' => AssistanceKind::Direction, 'assistance_question' => 'Which mirror?']);
    if ($status === TaskStatus::Running) {
        AgentThread::query()->where('task_group_id', $task->parent_id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    }
    TaskQuestion::query()->create([
        'task_id' => $task->parent_id, 'subtask_id' => $task->id, 'attempt' => 2, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    $turns = recording_t3_turns();
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject): void {
        if ($inject && str_contains($sql, 'task_questions') && str_starts_with(ltrim(strtolower($sql)), 'update')) {
            $inject = false;

            throw new RuntimeException('injected question link failure');
        }
    });

    expect(fn () => app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the documented choice.', 'author' => 'operator',
    ]))->toThrow(RuntimeException::class);

    $task->refresh();
    expect($task->assistance_requested)->toBeTrue()
        ->and($task->parent->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->direction_relay_comment_id)->toBeNull()
        ->and($task->resolution_delivered_comment_id)->toBeNull()
        ->and($task->review_attempt)->toBe(3)
        ->and($task->completion_attempt)->toBe(2)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBeNull();

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the documented choice.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id)
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->parent->fresh()?->assistance_requested)->toBeFalse()
        ->and($turns->threads)->toBe(['reviewer-thread', 'reviewer-thread']);
    if ($status === TaskStatus::Running) {
        expect($task->direction_relay_comment_id)->toBe($comment->id)
            ->and($task->completion_attempt)->toBe(2)
            ->and($task->review_attempt)->toBe(3);
    } else {
        expect($task->direction_relay_comment_id)->toBeNull()
            ->and($task->review_attempt)->toBe(4)
            ->and($task->review_notified_attempt)->toBe(4)
            ->and($task->resolution_delivered_comment_id)->toBe($comment->id);
    }
})->with([
    'running relay' => TaskStatus::Running,
    'review' => TaskStatus::Reviewing,
]);

it('records an operator direction request as a question', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['assistance_requested' => false, 'assistance_reason' => null]);
    $task->parent->update(['assistance_requested' => false, 'assistance_reason' => null]);

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'assistance_requested', 'body' => 'Which database should this use?', 'author' => 'operator',
    ]);

    $question = TaskQuestion::query()->sole();
    expect($question->asked_by)->toBe(QuestionAsker::Operator)
        ->and($question->question)->toBe('Which database should this use?')
        ->and($question->status)->toBe(QuestionStatus::Escalated)
        ->and($question->cause)->toBeNull()
        ->and($question->opened_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->parent->fresh()?->escalations)->toBe(1);
});

it('sends a resolution for a blocked review to the reviewer and does not request the review again', function (): void {
    $task = blocked_task(TaskStatus::Reviewing);
    $turns = recording_t3_turns();

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Approve without the migration check.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect($turns->threads)->toBe(['reviewer-thread'])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->review_attempt)->toBe(4)
        ->and($task->review_notified_attempt)->toBe(4)
        ->and($task->completion_attempt)->toBe(2)
        ->and($task->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->parent->assistance_requested)->toBeFalse();
});

it('starts a fresh subtask reviewer with a review resolution when that thread does not exist', function (): void {
    $project = Project::query()->create([
        'name' => 'resolution-target', 'slug' => 'resolution-target',
        'repository_url' => 'git@example.test:resolution-target.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'resolution-target-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.191', 'wireguard_ip' => '10.44.0.191',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-136',
        'checkout_path' => '/tmp/task-136', 'branch' => 'task-136', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Resolutions', 'brief' => 'Route each resolution to its subtask.',
        'status' => TaskGroupStatus::Reviewing,
        'assistance_requested' => true, 'assistance_reason' => 'The review diff could not be read.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $earlier = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Names', 'brief' => 'Name the records.',
        'status' => TaskStatus::Completed,
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Routes', 'brief' => 'Route the resolution.',
        'status' => TaskStatus::Reviewing, 'assistance_requested' => true, 'assistance_reason' => 'The review diff could not be read.',
        'review_attempt' => 3, 'review_notified_attempt' => null, 'communication_failures' => 5, 'completion_attempt' => 2,
    ]);
    $earlierReviewer = test_agent_thread($group, 'subtask-1-reviewer');
    $earlierReviewer->update(['task_id' => $earlier->id]);
    $group->update(['reviewer_agent_thread_id' => $earlierReviewer->id]);
    $implementer = test_agent_thread($group, 'subtask-2-implementer', $task);
    $task->update(['implementer_agent_thread_id' => $implementer->id]);
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    $reader = new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'idle'],
                'latestTurn' => ['id' => $threadId.'-turn', 'state' => 'completed'],
            ]];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Ship the names as they are.', 'author' => 'operator',
    ]);
    $task->refresh();
    $started = array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? '') === 'send',
    ));

    expect($started)->toBe([])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->parent->assistance_requested)->toBeFalse()
        ->and($task->communication_failures)->toBe(0)
        ->and($task->review_attempt)->toBe(3)
        ->and($task->review_notified_attempt)->toBeNull()
        ->and($task->resolution_delivered_comment_id)->toBeNull()
        ->and($task->completion_attempt)->toBe(2);

    app(TaskExtensionState::class)->enable();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app(TaskScheduler::class)->tick();

    $task->refresh();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    $opening = array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? '') === 'create',
    ));
    $threadIds = array_map(static fn (array $command): string => (string) ($command['threadId'] ?? ''), $opening);
    $text = implode("\n", array_map(static fn (array $command): string => (string) data_get($command, 'message.text'), $opening));

    expect($reviewer->id)->not->toBe($earlierReviewer->id)
        ->and($task->parent->reviewer_agent_thread_id)->toBe($reviewer->id)
        ->and($task->review_attempt)->toBe(3)
        ->and($task->review_notified_attempt)->toBe(3)
        ->and($task->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($threadIds)->toBe([$reviewer->external_id])
        ->and($threadIds)->not->toContain('subtask-1-reviewer')
        ->and($text)->toContain('Ship the names as they are.');
});

it('sends a resolution for a blocked implementation to the implementer', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $turns = recording_t3_turns();

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Dependencies are installed now.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect($turns->threads)->toBe(['implementer-thread'])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->completion_attempt)->toBe(3)
        ->and($task->review_attempt)->toBe(3);
});
