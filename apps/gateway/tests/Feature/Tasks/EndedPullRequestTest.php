<?php

declare(strict_types=1);

use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

/** An earlier approval is published, but the later implementer and reviewer still hold work. */
function ended_pr_group(string $state, string $status = 'running'): Task
{
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::response($state === 'missing' ? [] : [
            ['number' => 42, 'html_url' => 'https://github.com/acme/orbit/pull/42', 'state' => 'closed',
                'merged_at' => $state === 'merged' ? '2026-10-08T10:00:00Z' : null],
        ]),
    ]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'ORB-152', 'slug' => 'orb-152',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Early merge', 'brief' => 'Keep the remaining work.',
        'status' => $status, 'assistance_requested' => true, 'assistance_reason' => 'An earlier question.',
        'pr_url' => $status === 'settling' ? 'https://github.com/acme/orbit/pull/42' : null,
    ]);
    foreach (['completed', 'running', 'reviewing', 'todo'] as $position => $taskStatus) {
        $task = Task::query()->create([
            'parent_id' => $group->id, 'position' => $position + 1,
            'title' => 'Work '.($position + 1), 'brief' => 'Do this work.', 'status' => $taskStatus,
        ]);
        if (! in_array($taskStatus, ['running', 'reviewing'], true)) {
            continue;
        }
        $role = $taskStatus === 'running' ? 'implementer' : 'reviewer';
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id,
            'driver' => 'pi', 'runtime_key' => 'test:'.$group->id,
            'external_id' => $role, 'role' => $role, 'state' => 'working',
        ]);
        if ($role === 'implementer') {
            $task->update(['implementer_agent_thread_id' => $thread->id]);
        } else {
            $group->update(['reviewer_agent_thread_id' => $thread->id]);
        }
    }

    return $group->fresh(['tasks']);
}

/** A driver boundary double records accepted keys, including a lost response after acceptance. */
function ended_pr_driver(): object
{
    $recording = (object) ['state' => AgentThreadState::Working, 'inputRequests' => [], 'calls' => [], 'accepted' => [], 'failure' => null];
    $driver = Mockery::mock(AgentDriver::class);
    $driver->shouldReceive('key')->andReturn('pi');
    $driver->shouldReceive('observe')->andReturnUsing(static fn (): AgentObservation => new AgentObservation($recording->state, inputRequests: $recording->inputRequests));
    $driver->shouldReceive('interrupt')->never();
    $driver->shouldReceive('create')->never();
    $driver->shouldReceive('send')->andReturnUsing(static function (AgentThread $thread, string $message, ?string $key = null) use ($recording): void {
        $task = Task::query()->findOrFail($thread->task_id);
        expect($task->getAttribute('ended_pr_notice_state'))->toBe('pending')
            ->and($task->getAttribute('ended_pr_notice_key'))->toBe($key)
            ->and($task->getAttribute('ended_pr_notice_thread_id'))->toBe($thread->id);
        $recording->calls[] = ['thread' => $thread->id, 'message' => $message, 'key' => $key];
        if ($recording->failure === 'before') {
            throw new AgentDriverException('Send failed.');
        }
        $recording->accepted[$key] = $message;
        if ($recording->failure === 'after') {
            throw new AgentDriverException('Response lost after acceptance.');
        }
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    return $recording;
}

it('stops for an ended pull request before a later subtask approval and sends each acting thread one notice without interrupting', function (string $state, string $status): void {
    $this->freezeTime();
    $group = ended_pr_group($state, $status);
    if ($status === 'running') {
        $group->update(['assistance_requested' => false, 'assistance_reason' => null]);
    }
    $driver = ended_pr_driver();
    [$completed, $running, $reviewing, $todo] = $group->tasks->all();
    $reason = "Watched pull request ended: https://github.com/acme/orbit/pull/42 is {$state}. Open subtasks: #{$running->id} Work 2, #{$reviewing->id} Work 3, #{$todo->id} Work 4.";

    app(TaskScheduler::class)->tick();

    expect($group->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($reason)
        ->status->value->toBe($status)->watched_pr_url->toBe('https://github.com/acme/orbit/pull/42');
    foreach ([$running, $reviewing] as $task) {
        expect($task->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($reason)
            ->ended_pr_notice_state->toBe('pending')->ended_pr_notice_key->toBeString();
    }
    expect($completed->fresh())->status->value->toBe('completed')->assistance_requested->toBeFalse();
    expect($todo->fresh())->status->value->toBe('todo')->ended_pr_notice_key->toBeNull();
    expect($driver->calls)->toBe([]);

    $comment = app(StoreTaskCommentAction::class)->execute($running->fresh(), [
        'type' => 'resolution', 'body' => 'Continue anyway.', 'author' => 'operator',
    ]);
    expect($comment->body)->toBe('Continue anyway.');
    expect($running->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($reason);
    expect($driver->calls)->toBe([]);

    $driver->state = AgentThreadState::Done;
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($driver->calls)->toHaveCount(2);
    expect(Activity::query()->where('description', 'ended pull request notice delivered')->count())->toBe(2);
    expect(array_column($driver->calls, 'thread'))->toBe([
        $running->implementer_agent_thread_id, $group->reviewer_agent_thread_id,
    ]);
    expect(array_column($driver->calls, 'message'))->toBe([$reason, $reason]);
    foreach ([$running, $reviewing] as $task) {
        expect($task->fresh())->ended_pr_notice_state->toBe('delivered')->assistance_requested->toBeTrue();
    }
    // Even if no acting subtask remains, the waiting work must not start while assistance stands.
    $running->update(['status' => 'completed']);
    $reviewing->update(['status' => 'completed']);
    $this->travel(61)->seconds();
    app(TaskScheduler::class)->tick();
    expect($todo->fresh())->status->value->toBe('todo');
    expect($group->fresh())->assistance_reason->toBe($reason)->status->value->toBe($status);
    Http::assertSentCount(3);
})->with([
    'merged while running (ORB-152)' => ['merged', 'running'],
    'closed while reviewing' => ['closed', 'reviewing'],
    'merged while settling with open work' => ['merged', 'settling'],
]);

it('retries an ended pull request notice with the committed key after a failed or lost send', function (string $failure): void {
    $group = ended_pr_group('merged');
    $driver = ended_pr_driver();
    app(TaskScheduler::class)->tick();
    expect($group->fresh()->assistance_reason)->toStartWith('Watched pull request ended: ');
    $keys = Task::query()->where('parent_id', $group->id)->whereIn('status', ['running', 'reviewing'])
        ->pluck('ended_pr_notice_key')->all();
    $driver->state = AgentThreadState::Done;
    $driver->failure = $failure;

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('parent_id', $group->id)->where('ended_pr_notice_state', 'pending')->count())->toBe(2);
    expect($group->fresh())->assistance_requested->toBeTrue();
    expect(Activity::query()->where('description', 'ended pull request notice delivery failed')->count())->toBe(2);
    $driver->failure = null;
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect(array_column($driver->calls, 'key'))->toBe([...$keys, ...$keys]);
    expect($driver->accepted)->toHaveCount(2);
    expect(Task::query()->where('parent_id', $group->id)->where('ended_pr_notice_state', 'delivered')->count())->toBe(2);
})->with(['before acceptance' => 'before', 'after acceptance' => 'after']);

it('retries an ended pull request notice after acceptance but before delivered was committed', function (): void {
    $group = ended_pr_group('merged');
    $driver = ended_pr_driver();
    app(TaskScheduler::class)->tick();
    $running = $group->tasks->firstWhere('status', TaskStatus::Running)->fresh();
    $key = $running->ended_pr_notice_key;
    $crash = true;
    Task::updating(static function (Task $task) use (&$crash): void {
        if ($crash && $task->isDirty('ended_pr_notice_state') && $task->ended_pr_notice_state === 'delivered') {
            $crash = false;
            throw new RuntimeException('Process stopped before delivered was committed.');
        }
    });
    $driver->state = AgentThreadState::Done;

    expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class, 'Process stopped');

    expect($running->fresh())->ended_pr_notice_key->toBe($key)->ended_pr_notice_state->toBe('pending');
    expect($group->fresh())->assistance_requested->toBeTrue();
    expect($driver->accepted)->toHaveCount(1);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect(array_column($driver->calls, 'key'))->toHaveCount(3)
        ->and($driver->calls[0]['key'])->toBe($key)->and($driver->calls[1]['key'])->toBe($key);
    expect($driver->accepted)->toHaveCount(2);
    expect($running->fresh())->ended_pr_notice_state->toBe('delivered');
});

it('starts neither a subtask nor a reviewer after an ended pull request even through scheduler entry points', function (): void {
    $group = ended_pr_group('closed', 'reviewing');
    $driver = ended_pr_driver();
    app(TaskScheduler::class)->tick();
    [$completed, $running, $reviewing, $todo] = $group->tasks->all();
    $scheduler = app(TaskScheduler::class);

    $scheduler->startTask($todo->fresh());
    $scheduler->settleImplementer($running->fresh());
    $scheduler->acceptReview($reviewing->fresh());

    expect($running->fresh())->status->value->toBe('running');
    expect($reviewing->fresh())->status->value->toBe('reviewing');
    expect($todo->fresh())->status->value->toBe('todo');
    // Explicitly cancelling a subtask still must not start its successor or clear this group reason.
    $scheduler->cancelRunningSubtask($group->fresh(), $running->fresh(), static function (): void {});
    expect($running->fresh())->status->value->toBe('cancelled');
    expect($todo->fresh())->status->value->toBe('todo');
    expect($group->fresh())->assistance_requested->toBeTrue()->assistance_reason->toStartWith('Watched pull request ended: ');
    expect($driver->calls)->toBe([]);
});

it('stores no ended pull request notice when an acting thread does not exist', function (): void {
    $group = ended_pr_group('merged');
    $driver = ended_pr_driver();
    $group->tasks()->update(['implementer_agent_thread_id' => null]);
    $group->update(['reviewer_agent_thread_id' => null]);
    AgentThread::query()->where('task_group_id', $group->id)->delete();

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('parent_id', $group->id)->whereNotNull('ended_pr_notice_key')->count())->toBe(0);
    expect($group->fresh())->assistance_requested->toBeTrue()->assistance_reason->toStartWith('Watched pull request ended: ');
    expect($driver->calls)->toBe([]);
});

it('does not auto complete open work from an ended reviewed pull request when the branch watch has no result', function (string $state): void {
    $group = ended_pr_group('missing', 'settling');
    $driver = ended_pr_driver();
    $watcher = Mockery::mock(TaskPullRequestWatcher::class);
    $watcher->shouldReceive('health')->once()->andReturn(new TaskPullRequestHealth($state));
    app()->instance(TaskPullRequestWatcher::class, $watcher);

    app(TaskScheduler::class)->tick();

    expect($group->fresh())->status->value->toBe('settling')->assistance_reason->toBe('An earlier question.')
        ->watched_pr_url->toBeNull();
    expect($group->tasks()->whereIn('status', ['todo', 'running', 'reviewing'])->count())->toBe(3);
    expect($driver->calls)->toBe([]);
})->with(['merged', 'closed']);

it('does not retry a committed approval push while ended pull request assistance stands', function (): void {
    $group = ended_pr_group('merged', 'reviewing');
    $driver = ended_pr_driver();
    $node = Node::query()->create([
        'name' => 'ended-pr-node', 'platform' => 'linux', 'status' => 'active',
        'public_ssh_host' => '10.44.0.194', 'wireguard_ip' => '10.44.0.194',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $group->project_id, 'node_id' => $node->id, 'name' => 'ended-pr-workspace',
        'checkout_path' => '/tmp/ended-pr-workspace', 'status' => 'source_resolved',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $reviewing = $group->tasks->firstWhere('status', TaskStatus::Reviewing);
    $receipt = $reviewing->comments()->create([
        'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Approved before the external merge.',
        'author' => 'reviewer', 'review_attempt' => $reviewing->review_attempt,
        'receipt_hash' => str_repeat('c', 64), 'commit_sha' => str_repeat('a', 40), 'posted_at' => now(),
    ]);
    $publisher = Mockery::mock(TaskPullRequestPublisher::class);
    $publisher->shouldReceive('push')->never();
    $publisher->shouldReceive('publish')->never();
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh())->assistance_reason->toStartWith('Watched pull request ended: ')->status->value->toBe('reviewing');
    expect($reviewing->fresh())->status->value->toBe('reviewing')->review_handled_comment_id->toBeNull()
        ->communication_failures->toBe(0);
    expect($receipt->fresh())->commit_sha->toBe(str_repeat('a', 40));
    expect($driver->calls)->toBe([]);
});

it('notifies the ended pull request subtask reviewer rather than an earlier group reviewer', function (): void {
    $group = ended_pr_group('closed');
    $driver = ended_pr_driver();
    $reviewerId = $group->reviewer_agent_thread_id;
    $old = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks->first()->id,
        'driver' => 'pi', 'runtime_key' => 'test:'.$group->id, 'external_id' => 'old-reviewer',
        'role' => 'reviewer', 'state' => 'done',
    ]);
    $group->update(['reviewer_agent_thread_id' => $old->id]);
    $driver->state = AgentThreadState::Done;

    app(TaskScheduler::class)->tick();

    expect(array_column($driver->calls, 'thread'))->toBe([
        $group->tasks->firstWhere('status', TaskStatus::Running)->implementer_agent_thread_id, $reviewerId,
    ]);
    expect($group->tasks->firstWhere('status', TaskStatus::Reviewing)->fresh())->ended_pr_notice_state->toBe('delivered')
        ->ended_pr_notice_thread_id->toBe($reviewerId);
});

it('delivers an ended pull request notice to a stopped input-waiting thread but leaves active input requests alone', function (bool $pendingInput): void {
    $group = ended_pr_group('closed');
    $driver = ended_pr_driver();
    $driver->state = AgentThreadState::AskingForInput;
    $driver->inputRequests = $pendingInput ? [new AgentInputRequest('approval-1', 'approval')] : [];

    app(TaskScheduler::class)->tick();

    $acting = $group->tasks->whereIn('status', [TaskStatus::Running, TaskStatus::Reviewing]);
    foreach ($acting as $task) {
        expect($task->fresh())->ended_pr_notice_state->toBe($pendingInput ? 'pending' : 'delivered');
    }
    expect($driver->calls)->toHaveCount($pendingInput ? 0 : 2);
    expect($group->fresh())->assistance_requested->toBeTrue();
    if ($pendingInput) {
        $keys = $acting->map(static fn (Task $task): ?string => $task->fresh()->ended_pr_notice_key)->values()->all();
        $driver->inputRequests = [];
        app(TaskScheduler::class)->tick();
        expect(array_column($driver->calls, 'key'))->toBe($keys);
    }
    app(TaskScheduler::class)->tick();
    expect($driver->calls)->toHaveCount(2);
})->with(['stopped with no pending inputs' => false, 'active input request' => true]);

it('preserves an ended pull request hold established while a resolution send is in flight', function (TaskStatus $status): void {
    $group = ended_pr_group('missing');
    $task = $group->tasks->firstWhere('status', $status);
    $task->update(['assistance_requested' => true, 'assistance_reason' => 'An earlier question.']);
    $driver = Mockery::mock(AgentDriver::class);
    $driver->shouldReceive('key')->andReturn('pi');
    $driver->shouldReceive('observe')->andReturn(new AgentObservation(AgentThreadState::Working));
    $driver->shouldReceive('interrupt')->never();
    $driver->shouldReceive('send')->once()->withArgs(static fn (AgentThread $thread, string $message): bool => $thread->task_id === $task->id && $message === TaskTurnFetchNotice::Failed."\n\nAnswer to the earlier question.")
        ->andReturnUsing(static function () use ($group): void {
            $group->update(['watched_pr_url' => 'https://github.com/acme/orbit/pull/42', 'watched_pr_state' => 'merged']);
            app(RequestEndedPullRequestAssistanceAction::class)->execute($group->fresh(['tasks']));
        });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $attempt = $status === TaskStatus::Running ? $task->completion_attempt : $task->review_attempt;

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh(), [
        'type' => 'resolution', 'body' => 'Answer to the earlier question.', 'author' => 'operator',
    ]);

    expect($comment->fresh())->body->toBe('Answer to the earlier question.');
    expect($task->fresh())->assistance_requested->toBeTrue()->assistance_reason->toStartWith('Watched pull request ended: ')
        ->resolution_delivered_comment_id->toBeNull();
    expect($status === TaskStatus::Running ? $task->fresh()->completion_attempt : $task->fresh()->review_attempt)->toBe($attempt);
    expect($group->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($task->fresh()->assistance_reason);
    expect(Activity::query()->where('description', 'resolution delivered')->count())->toBe(0);
})->with(['implementer' => TaskStatus::Running, 'reviewer' => TaskStatus::Reviewing]);

it('preserves a current ended pull request reason when holding a resolution for a missing reviewer', function (bool $parentHold): void {
    $group = ended_pr_group('missing');
    $task = $group->tasks->firstWhere('status', TaskStatus::Reviewing);
    $task->update(['assistance_requested' => true, 'assistance_reason' => 'An earlier question.']);
    $task->load('parent');
    AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->delete();
    $driver = ended_pr_driver();
    $reason = 'Watched pull request ended: https://github.com/acme/orbit/pull/42 is closed. Open subtasks: #'.$task->id.' '.$task->title.'.';
    // The comment has been written using the old assistance snapshot. Another writer establishes
    // the hold before resolution eligibility and the reviewer lookup finish using that snapshot.
    TaskComment::created(static function (TaskComment $comment) use ($group, $task, $parentHold, $reason): void {
        if ($comment->type === TaskCommentType::Resolution) {
            ($parentHold ? $group : $task)->fresh()->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
        }
    });

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Answer to the earlier question.', 'author' => 'operator',
    ]);

    expect(($parentHold ? $group : $task)->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($reason);
    expect($task->fresh())->assistance_requested->toBeTrue()->resolution_delivered_comment_id->toBeNull();
    expect($group->fresh())->assistance_requested->toBeTrue();
    expect($comment->fresh())->review_attempt->toBeNull();
    expect(Activity::query()->where('description', 'resolution held for reviewer')->count())->toBe(0);
    expect($driver->calls)->toBe([]);
})->with(['current parent reason' => true, 'current subtask reason' => false]);

it('keeps an ended pull request notice pending with its accepted key when the delivery audit fails', function (): void {
    $group = ended_pr_group('merged');
    $driver = ended_pr_driver();
    app(TaskScheduler::class)->tick();
    $running = $group->tasks->firstWhere('status', TaskStatus::Running)->fresh();
    $key = $running->ended_pr_notice_key;
    $failAudit = true;
    Activity::creating(static function (Activity $activity) use (&$failAudit): void {
        if ($failAudit && $activity->description === 'ended pull request notice delivered') {
            $failAudit = false;
            throw new RuntimeException('Delivery audit could not be written.');
        }
    });
    $driver->state = AgentThreadState::Done;

    expect(fn () => app(TaskScheduler::class)->tick())->toThrow(RuntimeException::class, 'Delivery audit could not be written.');

    expect($running->fresh())->ended_pr_notice_state->toBe('pending')->ended_pr_notice_key->toBe($key);
    expect($driver->accepted)->toHaveCount(1);
    expect(Activity::query()->where('description', 'ended pull request notice delivered')->count())->toBe(0);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($running->fresh())->ended_pr_notice_state->toBe('delivered')->ended_pr_notice_key->toBe($key);
    expect(array_column($driver->calls, 'key'))->toHaveCount(3)
        ->and($driver->calls[0]['key'])->toBe($key)->and($driver->calls[1]['key'])->toBe($key);
    expect($driver->accepted)->toHaveCount(2);
    expect(Activity::query()->where('description', 'ended pull request notice delivered')->count())->toBe(2);
});
