<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionActor;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSessionObserver;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Database\QueryException;
use Laravel\Ai\Classification;
use Tests\Support\FakeAgentDriver;

/** @return array{TaskGroup, Task, FakeAgentDriver, AgentDriverRegistry} */
function driver_group(): array
{
    $app = OrbitApp::query()->create(['name' => 'drivers', 'slug' => 'drivers', 'repository_url' => 'git@example.test:drivers.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'agent-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.5', 'public_ssh_host' => '10.44.0.5']);
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task', 'checkout_path' => '/srv/task', 'status' => 'source_resolved']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'implementer_agent_driver' => 'example', 'reviewer_agent_driver' => 'example', 'title' => 'Feature', 'brief' => 'Brief', 'status' => 'running']);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'Do the work', 'status' => 'running']);
    $driver = new FakeAgentDriver;
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    $spawner = app(AgentSpawner::class);
    $group->update(['reviewer_agent_thread_id' => $spawner->spawnReviewer($task)]);
    $task->update(['implementer_agent_thread_id' => $spawner->spawnImplementer($task)]);

    return [$group, $task, $driver, $registry];
}

it('creates the conversation for each role through the driver recorded for that role', function (): void {
    $app = OrbitApp::query()->create(['name' => 'roles', 'slug' => 'roles', 'repository_url' => 'git@example.test:roles.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'role-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.6', 'public_ssh_host' => '10.44.0.6']);
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task', 'checkout_path' => '/srv/roles', 'status' => 'source_resolved']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'implementer_agent_driver' => 'implementer-runtime', 'reviewer_agent_driver' => 'reviewer-runtime', 'title' => 'Feature', 'brief' => 'Brief', 'status' => 'running']);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'First', 'brief' => 'Do the work', 'status' => 'running']);
    $implementer = new FakeAgentDriver('implementer-runtime');
    $reviewer = new FakeAgentDriver('reviewer-runtime');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$implementer, $reviewer]));
    $spawner = app(AgentSpawner::class);

    $reviewerThread = AgentThread::query()->findOrFail($spawner->spawnReviewer($task));
    $implementerThread = AgentThread::query()->findOrFail($spawner->spawnImplementer($task));

    expect($reviewerThread->driver)->toBe('reviewer-runtime')
        ->and($implementerThread->driver)->toBe('implementer-runtime')
        ->and(array_column($reviewer->calls, 'operation'))->toBe(['create'])
        ->and(array_column($implementer->calls, 'operation'))->toBe(['create']);
});

it('creates and resumes conversations through a second driver without T3', function (): void {
    [$group, $task, $driver, $registry] = driver_group();
    $driver->observation = new AgentObservation(AgentThreadState::Done);
    $observer = new TaskSessionObserver(new AgentThreadObserver($registry), new NullTaskWorkspaceDiffReader);
    $observation = $observer->observe($group, $group->tasks()->firstOrFail());
    new TaskSessionActor($registry, new NullCoderSettleNotifier)->execute($group, $observation, new TaskSessionDecision(TaskSessionNextAction::ContinueImplementer, 1.0, 'Continue.'));

    expect($task->implementerThread->driver)->toBe('example')
        ->and($driver->calls[2])->toMatchArray(['operation' => 'send', 'thread' => 'conversation-2'])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

it('routes typed pending requests through the selected driver', function (): void {
    [$group, , $driver, $registry] = driver_group();
    $driver->observation = new AgentObservation(AgentThreadState::AskingForInput, [new AgentInputRequest('request-1', 'approval', ['command' => 'run tests'])]);
    $observation = new TaskSessionObserver(new AgentThreadObserver($registry), new NullTaskWorkspaceDiffReader)->observe($group, $group->tasks()->firstOrFail());
    new TaskSessionActor($registry, new NullCoderSettleNotifier)->execute($group, $observation, new TaskSessionDecision(TaskSessionNextAction::DrainApproval, 1.0, 'Approve.'));

    expect($driver->calls[2])->toMatchArray(['operation' => 'respond', 'request' => 'request-1', 'answers' => ['approve' => true]]);
});

it('persists each generic state and failure details without completing the Task', function (AgentThreadState $state): void {
    [$group, $task, $driver, $registry] = driver_group();
    $error = $state === AgentThreadState::Failed ? 'Agent turn failed.' : null;
    $driver->observation = new AgentObservation($state, error: $error, tokens: 200);
    new AgentThreadObserver($registry)->observe($task->implementerThread);

    expect($task->implementerThread->fresh()->state)->toBe($state)
        ->and($task->implementerThread->fresh()->error)->toBe($error)
        ->and($task->implementerThread->fresh()->tokens)->toBe(200)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running);
})->with(AgentThreadState::cases());

it('retains state and metrics on an unavailable observation and waits without classification', function (): void {
    [$group, $task, $driver, $registry] = driver_group();
    $thread = $task->implementerThread;
    $thread->update(['state' => AgentThreadState::Done, 'tokens' => 900, 'observed_at' => now()->subMinute()]);
    $observedAt = $thread->observed_at;
    $result = new AgentThreadObserver($registry)->observe($thread);
    Classification::fake();
    app(TaskExtensionState::class)->enable();
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    $decisions = app(TaskScheduler::class)->tick();

    expect($result)->toBeNull()
        ->and($thread->fresh()->state)->toBe(AgentThreadState::Done)
        ->and($thread->fresh()->tokens)->toBe(900)
        ->and($thread->fresh()->observed_at->equalTo($observedAt))->toBeTrue()
        ->and($thread->fresh()->observation_error)->toBe('Agent observation unavailable.')
        ->and($decisions[0]->action)->toBe(TaskSessionNextAction::Noop)
        ->and($driver->calls)->toHaveCount(2);
    Classification::assertNothingClassified();
});

it('leaves never observed state unknown and clears failure details after retry starts', function (): void {
    [, $task, $driver, $registry] = driver_group();
    $thread = $task->implementerThread;
    $observer = new AgentThreadObserver($registry);
    $observer->observe($thread);
    expect($thread->fresh()->state)->toBeNull();
    $driver->observation = new AgentObservation(AgentThreadState::Failed, error: 'Failed');
    $observer->observe($thread);
    $driver->observation = new AgentObservation(AgentThreadState::Working);
    $observer->observe($thread);
    expect($thread->fresh()->state)->toBe(AgentThreadState::Working)
        ->and($thread->fresh()->error)->toBeNull()
        ->and($thread->fresh()->observation_error)->toBeNull();
});

it('observes every attached implementer thread', function (): void {
    [$group, $task, $driver, $registry] = driver_group();
    $driver->observation = new AgentObservation(AgentThreadState::Idle);
    $old = test_agent_thread($group, 'old-attempt', $task);
    $observation = new TaskSessionObserver(new AgentThreadObserver($registry), new NullTaskWorkspaceDiffReader)->observe($group, $group->tasks()->firstOrFail());

    expect(array_column($observation->toArray()['threads'], 'thread_id'))->toContain($old->id)
        ->toContain($task->implementer_agent_thread_id);
});

it('refuses unknown drivers and unsupported operations explicitly', function (): void {
    [, $task, , $registry] = driver_group();
    expect(fn () => $registry->get('unknown'))->toThrow(AgentDriverException::class, 'unavailable')
        ->and(fn () => $registry->get('example')->interrupt($task->implementerThread))->toThrow(AgentDriverException::class, 'does not support interruption');
});

it('scopes external identifiers to the driver and runtime', function (): void {
    [$group, $task] = driver_group();
    $thread = $task->implementerThread;
    $other = $thread->replicate();
    $other->driver = 'another-driver';
    $other->save();
    $third = $thread->replicate();
    $third->runtime_key = 'another-runtime';
    $third->save();

    expect(AgentThread::query()->where('external_id', $thread->external_id)->count())->toBe(3);
    $duplicate = $thread->replicate();
    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});

it('routes an attached conversation without a legacy pointer', function (): void {
    [$group, $task, $driver] = driver_group();
    $task->update(['implementer_agent_thread_id' => null]);
    $driver->observation = new AgentObservation(AgentThreadState::Done);
    app(TaskExtensionState::class)->enable();
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($driver->calls)->toHaveCount(3)
        ->and($driver->calls[2]['operation'])->toBe('send')
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

it('rejects a drain when the requested input is no longer available', function (): void {
    [$group, , $driver, $registry] = driver_group();
    $driver->observation = new AgentObservation(AgentThreadState::Done);
    $observation = new TaskSessionObserver(new AgentThreadObserver($registry), new NullTaskWorkspaceDiffReader)->observe($group, $group->tasks()->firstOrFail());

    expect(fn () => new TaskSessionActor($registry, new NullCoderSettleNotifier)->execute($group, $observation, new TaskSessionDecision(TaskSessionNextAction::DrainApproval, 1.0, 'Approve.')))
        ->toThrow(AgentDriverException::class, 'No matching agent input request');
    expect($driver->calls)->toHaveCount(2);
});

it('alerts once after a continuous observation outage and rearms after recovery', function (): void {
    $this->freezeTime();
    [$group, , $driver] = driver_group();
    config()->set('orbit.tasks.observation_grace_seconds', 120);
    app(TaskExtensionState::class)->enable();
    $notifier = new class implements CoderSettleNotifier
    {
        public int $alerts = 0;

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->alerts++;
        }

        public function assistance(TaskGroup $group, string $reason): void {}
    };
    app()->instance(CoderSettleNotifier::class, $notifier);
    $scheduler = app(TaskScheduler::class);

    expect($scheduler->tick()[0]->action)->toBe(TaskSessionNextAction::Noop);
    $this->travel(119)->seconds();
    expect($scheduler->tick()[0]->action)->toBe(TaskSessionNextAction::Noop);
    Classification::assertNothingClassified();
    $this->travel(1)->seconds();
    expect($scheduler->tick()[0]->action)->toBe(TaskSessionNextAction::EscalateCoder);
    $this->travel(10)->minutes();
    expect($scheduler->tick()[0]->action)->toBe(TaskSessionNextAction::Noop)
        ->and($notifier->alerts)->toBe(1);

    $driver->observation = new AgentObservation(AgentThreadState::Working);
    $scheduler->tick();
    expect($group->fresh()->agent_unavailable_since)->toBeNull()
        ->and($group->fresh()->agent_unavailable_notified_at)->toBeNull();
    $driver->observation = null;
    expect($scheduler->tick()[0]->action)->toBe(TaskSessionNextAction::Noop);
    $this->travel(120)->seconds();
    expect($scheduler->tick()[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($notifier->alerts)->toBe(2);
});

it('keeps unknown activity separate from failed transport', function (): void {
    [, $task, $driver, $registry] = driver_group();
    $driver->observation = new AgentObservation(null);
    $observed = new AgentThreadObserver($registry)->observe($task->implementerThread);

    expect($observed)->not->toBeNull()
        ->and($task->implementerThread->fresh()->state)->toBeNull()
        ->and($task->implementerThread->fresh()->observation_error)->toBeNull()
        ->and($task->implementerThread->fresh()->observed_at)->not->toBeNull();
});

it('rejects an older in-flight read after another reader records a new outcome', function (): void {
    [, $task, , $registry] = driver_group();
    $older = $task->implementerThread;
    $newer = $older->fresh();
    $observer = new AgentThreadObserver($registry);
    expect($observer->record($newer, new AgentObservation(AgentThreadState::Done, tokens: 500)))->toBeTrue();
    expect($observer->record($older, new AgentObservation(AgentThreadState::Working, tokens: 10)))->toBeFalse();
    expect($older->fresh()->state)->toBe(AgentThreadState::Done)
        ->and($older->fresh()->tokens)->toBe(500);
});
