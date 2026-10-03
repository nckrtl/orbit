<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewContext;
use App\Domain\Tasks\TaskReviewFindingsPacket;
use App\Domain\Tasks\TaskReviewPacket;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSettlingFixup;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskTurnReceipts;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Process\Process;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\FakeAgentDriver;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\ReviewFindings;
use Tests\Support\TestOrbitHome;

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('writes the uncut task context before the opening review', function (): void {
    $case = review_context_opening();
    $turn = $case['log']->turns[0];
    $task = $case['task']->fresh() ?? $case['task'];

    expect($turn['context'])->toBe(app(TaskReviewPacketBuilder::class)->reviewContext($task))
        ->and($turn['context'])->toContain($case['taskBrief'])
        ->and($turn['context'])->toContain($case['subtaskBrief'])
        ->and($turn['context'])->toContain($case['description'])
        ->and($turn['context'])->toContain($case['command'])
        ->and($turn['context'])->toContain($case['path'])
        ->and($turn['context'])->toContain('fails_on_base: true')
        ->and($turn['context'])->toContain($case['approval'])
        ->and($turn['context'])->toContain($case['resolution'])
        ->and($turn['message'])->not->toContain('GROUP-END')
        ->and($turn['message'])->not->toContain('SUBTASK-END')
        ->and($turn['message'])->not->toContain('DELIVERABLE-END')
        ->and($turn['message'])->not->toContain('COMMAND-END')
        ->and($turn['message'])->not->toContain('PATH-END')
        ->and($turn['message'])->not->toContain('APPROVAL-END')
        ->and($turn['message'])->not->toContain('RESOLUTION-END')
        ->and($turn['message'])->toContain(TaskReviewContext::Path.' holds the full brief.')
        ->and($turn['message'])->toContain(TaskReviewContext::Path.' holds the full resolution.')
        ->and(mb_strlen($turn['message']))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and(is_file(review_context_file_path($case['checkout']).'.new'))->toBeFalse();
});

it('writes full findings for a fresh feedback implementer and its reviewer without scheduler dispatch', function (bool $reserved, bool $stale): void {
    $case = review_context_opening();
    $plan = TaskSettlingFixup::reviewPlan(null,
        TaskReviewFindingsPacket::fromCandidate(ReviewFindings::candidate(
            str_repeat('Complete finding. ', 1500).'FINAL-FINDING',
            [ReviewFindings::comment()],
        )));
    $task = $case['task'];
    $task->update(['brief' => $plan->brief, 'fixup_problem' => $plan->identity, 'deliverables' => $plan->deliverables]);
    $path = review_context_file_path($case['checkout']);
    if ($stale) {
        file_put_contents($path, 'Stale findings from another turn.');
    } else {
        unlink($path);
    }
    $spawner = app(AgentSpawner::class);
    $threadId = $reserved ? $spawner->reserveImplementer($task) : null;
    $started = $spawner->spawnImplementer($task);
    expect($started)->not->toBeNull();
    if ($reserved) {
        expect($started)->toBe($threadId);
    }
    $implementer = $case['log']->turns[1];
    expect($implementer['context'])->toContain($plan->brief)->toContain('FINAL-FINDING')->toContain($case['taskBrief'])
        ->not->toContain('Stale findings from another turn.')
        ->and($implementer['message'])->toContain($plan->brief);

    app(AgentSpawner::class)->requestReview($task);
    $reviewer = $case['log']->turns[2];
    expect($reviewer['context'])->toContain($plan->brief)->toContain('FINAL-FINDING')
        ->and($reviewer['message'])->not->toContain('FINAL-FINDING')
        ->toContain(TaskReviewContext::Path.' holds the full brief.');
})->with([false, true])->with([false, true]);

it('fails closed when reserved feedback context installation fails and installs current context on retry', function (): void {
    $case = review_context_opening();
    $task = $case['task'];
    $plan = TaskSettlingFixup::reviewPlan(null, TaskReviewFindingsPacket::fromCandidate(ReviewFindings::candidate()));
    $task->update(['brief' => $plan->brief, 'fixup_problem' => $plan->identity, 'deliverables' => $plan->deliverables]);
    $path = review_context_file_path($case['checkout']);
    unlink($path);
    mkdir($path);
    $spawner = app(AgentSpawner::class);
    $reserved = $spawner->reserveImplementer($task);
    expect($reserved)->not->toBeNull();

    expect(fn () => $spawner->spawnImplementer($task))->toThrow(TaskTurnReceiptException::class);
    expect($case['log']->turns)->toHaveCount(1)
        ->and(AgentThread::query()->find($reserved))->toBeNull();

    rmdir($path);
    // A new reservation can also be interrupted before startup. Retry must not
    // assume that reserving the row installed its receipt or its current context.
    $replacement = $spawner->reserveImplementer($task);
    $current = TaskSettlingFixup::reviewPlan(null, TaskReviewFindingsPacket::fromCandidate(ReviewFindings::candidate('Current findings after interrupted startup.')));
    $task->update(['brief' => $current->brief]);
    expect($spawner->spawnImplementer($task))->toBe($replacement)
        ->and($case['log']->turns)->toHaveCount(2)
        ->and($case['log']->turns[1]['context'])->toContain($current->brief)->toContain($case['taskBrief'])
        ->and($case['log']->turns[1]['message'])->toContain($current->brief);
});

it('writes the uncut task context before a continued review', function (): void {
    $case = review_context_opening();
    $task = $case['task'];
    $group = $case['group'];
    $continued = str_repeat('Q', 2_100).'RESOLUTION-CONTINUE-END';
    $task->update(['status' => TaskStatus::Running, 'review_attempt' => $task->review_attempt + 1]);
    $group->update(['status' => TaskGroupStatus::Running]);
    TaskComment::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $task->id,
        'type' => TaskCommentType::Resolution,
        'body' => $continued,
        'author' => 'operator',
        'review_attempt' => $task->fresh()?->review_attempt,
        'posted_at' => now(),
    ]);

    app(TaskScheduler::class)->settleImplementer($task->fresh() ?? $task);

    $turn = $case['log']->turns[1];
    $fresh = $task->fresh() ?? $task;

    expect($turn['context'])->toBe(app(TaskReviewPacketBuilder::class)->reviewContext($fresh))
        ->and($turn['context'])->toContain($case['taskBrief'])
        ->and($turn['context'])->toContain($case['subtaskBrief'])
        ->and($turn['context'])->toContain($case['description'])
        ->and($turn['context'])->toContain($case['command'])
        ->and($turn['context'])->toContain($case['path'])
        ->and($turn['context'])->toContain($case['approval'])
        ->and($turn['context'])->toContain($continued)
        ->and($turn['message'])->not->toContain('GROUP-END')
        ->and($turn['message'])->not->toContain('SUBTASK-END')
        ->and($turn['message'])->not->toContain('DELIVERABLE-END')
        ->and($turn['message'])->not->toContain('COMMAND-END')
        ->and($turn['message'])->not->toContain('PATH-END')
        ->and($turn['message'])->not->toContain('APPROVAL-END')
        ->and($turn['message'])->not->toContain('RESOLUTION-CONTINUE-END')
        ->and($turn['message'])->not->toContain("Group brief\n")
        ->and($turn['message'])->not->toContain("Deliverables\n")
        ->and($turn['message'])->not->toContain("Earlier approved subtasks\n")
        ->and($turn['message'])->toContain(TaskReviewContext::Path.' holds the full brief.')
        ->and(mb_strlen($turn['message']))->toBeLessThanOrEqual(TaskReviewPacket::Limit);
});

it('counts a communication failure and does not send the review when the context file cannot be written', function (): void {
    Exceptions::fake();
    $project = Project::query()->create([
        'name' => 'context-failure',
        'slug' => 'context-failure',
        'repository_url' => 'git@example.test:context-failure.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'context-failure-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.76',
        'wireguard_ip' => '10.44.0.76',
        'user' => 'orbit',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'context-failure',
        'checkout_path' => '/tmp/context-failure',
        'branch' => 'task',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Context failure',
        'brief' => 'The review context cannot be written.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Write context',
        'brief' => 'Send no review when the file cannot be written.',
        'status' => TaskStatus::Running,
    ]);
    $driver = new FakeAgentDriver('pi');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app()->instance(TaskTurnReceipts::class, new class implements TaskTurnReceipts
    {
        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void
        {
            if ($context !== null) {
                throw new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.');
            }
        }

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return null;
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });
    app()->instance(AgentSpawner::class, new TaskAgentSpawner(
        app(AgentDriverRegistry::class),
        app(TaskReviewPacketBuilder::class),
        app(TaskWorkspaceMcp::class),
    ));

    app(TaskScheduler::class)->settleImplementer($task);

    expect($driver->calls)->toBe([])
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->review_notified_attempt)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing);
    Exceptions::assertReported(TaskTurnReceiptException::class);
});

it('does not send the review when the context path is a directory', function (): void {
    Exceptions::fake();
    $checkout = TestOrbitHome::scratch('context-directory');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    $path = review_context_file_path($checkout);
    mkdir($path, 0755, true);
    $project = Project::query()->create([
        'name' => 'context-directory',
        'slug' => 'context-directory',
        'repository_url' => 'git@example.test:context-directory.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'context-directory-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.74',
        'wireguard_ip' => '10.44.0.74',
        'user' => 'orbit',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'context-directory',
        'checkout_path' => $checkout,
        'branch' => 'task',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Context directory',
        'brief' => 'The context path is a directory.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Write context',
        'brief' => 'Send no review when the context path is a directory.',
        'status' => TaskStatus::Running,
    ]);
    $driver = new FakeAgentDriver('pi');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app()->instance(TaskTurnReceipts::class, review_context_receipts(new LocalShellSshExecutor));
    app()->instance(AgentSpawner::class, new TaskAgentSpawner(
        app(AgentDriverRegistry::class),
        app(TaskReviewPacketBuilder::class),
        app(TaskWorkspaceMcp::class),
    ));

    app(TaskScheduler::class)->settleImplementer($task);

    expect($driver->calls)->toBe([])
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->review_notified_attempt)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(is_dir($path))->toBeTrue()
        ->and(is_file($path))->toBeFalse()
        ->and(glob($path.'/*'))->toBe([]);
    Exceptions::assertReported(TaskTurnReceiptException::class);
});

/**
 * @return array{
 *     task: Task,
 *     group: Task,
 *     checkout: string,
 *     log: object,
 *     taskBrief: string,
 *     subtaskBrief: string,
 *     description: string,
 *     command: string,
 *     path: string,
 *     approval: string,
 *     resolution: string
 * }
 */
function review_context_opening(): array
{
    $checkout = TestOrbitHome::scratch('review-context');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    $project = Project::query()->create([
        'name' => 'review-context',
        'slug' => 'review-context',
        'repository_url' => 'git@example.test:review-context.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'review-context-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.75',
        'wireguard_ip' => '10.44.0.75',
        'user' => 'orbit',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'review-context',
        'checkout_path' => $checkout,
        'branch' => 'task',
        'status' => 'source_resolved',
        'starting_commit' => str_repeat('b', 40),
    ]);
    $taskBrief = "it's \$HOME\n".str_repeat('é', 2_100)."\nGROUP-END";
    $subtaskBrief = str_repeat('S', 2_100)."\nSUBTASK-END";
    $description = str_repeat('D', 400)."\nDELIVERABLE-END";
    $command = str_repeat('C', 300).'COMMAND-END';
    $path = 'apps/gateway/'.str_repeat('p', 200).'PATH-END.php';
    $approval = str_repeat('A', 400)."\nAPPROVAL-END";
    $resolution = str_repeat('R', 2_100)."\nRESOLUTION-END";
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Review context',
        'brief' => $taskBrief,
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $earlier = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Earlier work',
        'brief' => 'Done.',
        'status' => TaskStatus::Completed,
    ]);
    TaskComment::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $earlier->id,
        'type' => TaskCommentType::Approved,
        'body' => $approval,
        'author' => 'reviewer',
        'posted_at' => now(),
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Write context',
        'brief' => $subtaskBrief,
        'status' => TaskStatus::Running,
        'subtask_start_commit' => str_repeat('a', 40),
        'deliverables' => [TaskDeliverable::fromArray([
            'id' => 'context-file',
            'type' => 'command',
            'description' => $description,
            'command' => $command,
            'directory' => 'apps/gateway',
            'fails_on_base' => true,
            'paths' => [$path],
        ])->toArray()],
    ]);
    TaskComment::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $task->id,
        'type' => TaskCommentType::Resolution,
        'body' => $resolution,
        'author' => 'operator',
        'review_attempt' => $task->review_attempt,
        'posted_at' => now(),
    ]);
    $driver = new FakeAgentDriver('pi');
    $log = new class
    {
        /** @var list<array{message: string, context: ?string}> */
        public array $turns = [];
    };
    $driver->beforeTurn = function (string $message) use ($log, $checkout): void {
        $path = review_context_file_path($checkout);
        $log->turns[] = [
            'message' => $message,
            'context' => is_file($path) ? (string) file_get_contents($path) : null,
        ];
    };
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app()->instance(TaskTurnReceipts::class, review_context_receipts(new LocalShellSshExecutor));
    app()->instance(AgentSpawner::class, new TaskAgentSpawner(
        app(AgentDriverRegistry::class),
        app(TaskReviewPacketBuilder::class),
        app(TaskWorkspaceMcp::class),
    ));

    app(TaskScheduler::class)->settleImplementer($task);

    return [
        'task' => $task,
        'group' => $group,
        'checkout' => $checkout,
        'log' => $log,
        'taskBrief' => $taskBrief,
        'subtaskBrief' => $subtaskBrief,
        'description' => $description,
        'command' => $command,
        'path' => $path,
        'approval' => $approval,
        'resolution' => $resolution,
    ];
}

function review_context_file_path(string $checkout): string
{
    $process = new Process(['git', '-C', $checkout, 'rev-parse', '--git-path', 'orbit']);
    $process->mustRun();
    $orbit = trim($process->getOutput());

    return (str_starts_with($orbit, '/') ? $orbit : $checkout.'/'.$orbit).'/context.md';
}

function review_context_receipts(SshExecutor $transport): RemoteTaskTurnReceipts
{
    return new RemoteTaskTurnReceipts(new DevelopmentSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));
}
