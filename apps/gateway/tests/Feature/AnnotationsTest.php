<?php

declare(strict_types=1);

use App\Actions\Annotations\DispatchAnnotationsAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\Annotation;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function annotationFixture(): AppInstance
{
    $node = Node::query()->create(['name' => 'annotation-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.88', 'wireguard_ip' => '10.44.0.88']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $app = OrbitApp::query()->create(['name' => 'Annotation', 'slug' => 'annotation', 'repository_url' => 'https://example.test/annotation.git', 'default_branch' => 'main']);

    return AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'dev', 'environment' => 'development', 'source_layout' => 'worktree', 'checkout_path' => '/worktree', 'status' => 'active']);
}

/** @return array<string, mixed> */
function annotationInput(string $id = 'annotation-one'): array
{
    return ['id' => $id, 'threadId' => 'thread-one', 'comment' => 'Make the title smaller', 'element' => 'h1', 'elementPath' => 'body > h1', 'x' => 10, 'y' => 20, 'timestamp' => 1000, 'url' => 'https://annotation.test/page', 'pathname' => '/page'];
}

final class AnnotationThreadReader implements T3ThreadReader
{
    public string $state = 'running';

    public string $path = '/worktree';

    public function snapshot(Node $node, string $threadId): ?array
    {
        return ['thread' => ['id' => $threadId, 'worktreePath' => $this->path, 'runtimeMode' => 'approval-required', 'interactionMode' => 'default', 'latestTurn' => ['state' => $this->state]]];
    }
}
final class AnnotationDispatcher implements T3Dispatcher
{
    /** @var list<array<string, mixed>> */
    public array $commands = [];

    public bool $fail = false;

    public function dispatch(Node $node, array $command): array
    {
        $this->commands[] = $command;
        if ($this->fail) {
            throw new RuntimeException('token-must-never-leak');
        }

        return ['sequence' => 12, 'thread_id' => $command['threadId']];
    }
}

describe('Instance annotations', function (): void {
    it('persists idempotent submissions and rejects altered duplicates and untrusted state', function (): void {
        $instance = annotationFixture();
        $url = "/api/v1/instances/{$instance->id}/annotations";
        $this->postJson($url, [...annotationInput(), 'status' => 'resolved'])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.instanceId', $instance->id);
        $this->postJson($url, annotationInput())->assertCreated();
        expect(Annotation::query()->count())->toBe(1)->and(DB::table('annotation_events')->count())->toBe(1);
        $this->postJson($url, [...annotationInput(), 'comment' => 'Different'])->assertConflict();
        $this->postJson($url, [...annotationInput('bad'), 'x' => 'invalid'])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.command');
    });

    it('enforces peer access and Instance ownership for updates and event streams', function (): void {
        $instance = annotationFixture();
        $url = "/api/v1/instances/{$instance->id}/annotations";
        $this->postJson($url, annotationInput())->assertCreated();
        $other = $instance->replicate();
        $other->name = 'other';
        $other->checkout_path = '/other';
        $other->save();
        $this->postJson("/api/v1/instances/{$other->id}/annotations/annotation-one/status", ['status' => 'in_progress'])->assertNotFound();
        $peer = Node::query()->create(['name' => 'denied', 'status' => 'active', 'public_ssh_host' => '10.44.0.89', 'wireguard_ip' => '10.44.0.89']);
        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson($url)->assertForbidden();
        $this->get($url.'/events')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.199'])->postJson($url, annotationInput())->assertForbidden();
    });

    it('replays progress and completion after refresh and snapshots current state', function (): void {
        $instance = annotationFixture();
        $url = "/api/v1/instances/{$instance->id}/annotations";
        $cursor = $this->postJson($url, annotationInput())->assertCreated()->json('data.revision');
        $this->postJson($url.'/annotation-one/status', ['status' => 'resolved', 'summary' => 'done'])->assertConflict();
        $this->postJson($url.'/annotation-one/status', ['status' => 'in_progress'])->assertOk();
        $this->postJson($url.'/annotation-one/status', ['status' => 'resolved'])->assertUnprocessable();
        $this->postJson($url.'/annotation-one/status', ['status' => 'resolved', 'summary' => 'Reduced title size; checked browser'])->assertOk();
        $response = $this->withHeader('Last-Event-ID', (string) $cursor)->get($url.'/events')->assertOk();
        expect($response->streamedContent())->toContain('event: annotation.updated', 'event: snapshot', 'in_progress', 'resolved', 'Reduced title size');
        $this->postJson($url.'/annotation-one/status', ['status' => 'in_progress'])->assertConflict();
        $this->flushHeaders();
        $this->get($url.'/events?after=invalid')->assertUnprocessable();
    });

    it('delivers in order only when idle and explicitly completed, and retries the identical command', function (): void {
        $instance = annotationFixture();
        $url = "/api/v1/instances/{$instance->id}/annotations";
        $reader = new AnnotationThreadReader;
        $dispatcher = new AnnotationDispatcher;
        app()->instance(T3ThreadReader::class, $reader);
        app()->instance(T3Dispatcher::class, $dispatcher);
        $this->postJson($url, annotationInput())->assertCreated();
        $this->postJson($url, annotationInput('annotation-two'))->assertCreated();
        $action = app(DispatchAnnotationsAction::class);
        expect($action->execute())->toBe(0)->and($dispatcher->commands)->toBe([]);
        $this->travel(6)->seconds();
        $reader->state = 'completed';
        $dispatcher->fail = true;
        expect($action->execute())->toBe(0);
        $this->getJson($url)->assertJsonPath('data.0.delivery', 'error');
        expect(Annotation::query()->findOrFail('annotation-one')->error)->not->toContain('token-must-never-leak');
        $this->postJson($url.'/annotation-one/retry')->assertOk();
        $dispatcher->fail = false;
        expect($action->execute())->toBe(1)->and($dispatcher->commands[0])->toBe($dispatcher->commands[1]);
        expect($dispatcher->commands[1]['message']['text'])->toContain('in_progress', 'resolved', '/api/v1/instances/'.$instance->id.'/annotations/annotation-one/status');
        expect($dispatcher->commands[1]['runtimeMode'])->toBe('approval-required');
        expect($action->execute())->toBe(0);
        $this->postJson($url.'/annotation-one/status', ['status' => 'in_progress'])->assertOk();
        $this->postJson($url.'/annotation-one/status', ['status' => 'resolved', 'summary' => 'Done'])->assertOk();
        expect($action->execute())->toBe(1)->and($dispatcher->commands)->toHaveCount(3);
    });

    it('does not deliver to a thread in another worktree', function (): void {
        $instance = annotationFixture();
        $reader = new AnnotationThreadReader;
        $reader->path = '/other';
        $dispatcher = new AnnotationDispatcher;
        app()->instance(T3ThreadReader::class, $reader);
        app()->instance(T3Dispatcher::class, $dispatcher);
        $this->postJson("/api/v1/instances/{$instance->id}/annotations", annotationInput())->assertCreated();
        app(DispatchAnnotationsAction::class)->execute();
        expect($dispatcher->commands)->toBe([])->and(Annotation::query()->firstOrFail()->delivery)->toBe('error');
    });
});

it('broadcasts committed annotation changes without comments or screenshots and discards rolled back notices', function (): void {
    $instance = annotationFixture();
    Event::fake([RecordBroadcast::class]);
    $url = "/api/v1/instances/{$instance->id}/annotations";
    $revision = $this->postJson($url, annotationInput())->assertCreated()->json('data.revision');
    Event::assertDispatched(RecordBroadcast::class, fn ($event): bool => $event->type === RecordEventType::AnnotationUpdated && $event->data === ['id' => 'annotation-one', 'instanceId' => $instance->id, 'revision' => $revision]);
    Event::fake([RecordBroadcast::class]);
    DB::beginTransaction();
    $this->postJson($url, annotationInput('rolled-back'))->assertCreated();
    DB::rollBack();
    Event::assertNotDispatched(RecordBroadcast::class);
});

it('creates task-backed annotations and keeps their lifecycle outside the managed scheduler', function (): void {
    $instance = annotationFixture();
    $url = "/api/v1/instances/{$instance->id}/annotations";
    $taskId = $this->postJson($url, annotationInput())->assertCreated()->json('data.taskId');
    $task = Task::query()->findOrFail($taskId);
    expect($task->type)->toBe(TaskType::Annotation);
    expect($task->taskGroup->execution_mode)->toBe(TaskExecutionMode::ExistingThread);
    expect($task->taskGroup->taskable_id)->toBe($instance->id);
    app(TaskExtensionState::class)->enable();
    $scheduler = app(TaskScheduler::class);
    expect($scheduler->claimNext())->toBeNull();
    $this->postJson($url.'/annotation-one/status', ['status' => 'in_progress'])->assertOk();
    expect($task->refresh()->status)->toBe(TaskStatus::Running);
    expect($scheduler->tick())->toBe([]);
    expect(app(TaskConcurrencyGuard::class)->activeForNode($instance->node_id))->toBe(0);
    expect(fn () => $scheduler->startTask($task))->toThrow(ResourceOperationException::class);
    expect(fn () => app(CompleteTaskGroupAction::class)->execute($task->taskGroup))->toThrow(ResourceOperationException::class);
    expect(fn () => app(CancelTaskGroupAction::class)->execute($task->taskGroup))->toThrow(ResourceOperationException::class);
    $this->postJson($url.'/annotation-one/status', ['status' => 'resolved', 'summary' => 'Implemented and verified'])->assertOk();
    expect($task->refresh()->status)->toBe(TaskStatus::Completed);
    expect($task->completion_summary)->toBe('Implemented and verified');
    expect($task->taskGroup->status)->toBe(TaskGroupStatus::Completed);
    expect($instance->fresh())->not->toBeNull();
});

it('keeps an unassigned task pending until retry assigns its thread and rejects retargeting a dispatched task', function (): void {
    $instance = annotationFixture();
    $url = "/api/v1/instances/{$instance->id}/annotations";
    $this->postJson($url, [...annotationInput(), 'threadId' => null])->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->postJson($url.'/annotation-one/retry', ['threadId' => 'thread-one'])->assertOk()->assertJsonPath('data.threadId', 'thread-one');
    $reader = new AnnotationThreadReader;
    $reader->state = 'completed';
    $dispatcher = new AnnotationDispatcher;
    $dispatcher->fail = true;
    app()->instance(T3ThreadReader::class, $reader);
    app()->instance(T3Dispatcher::class, $dispatcher);
    app(DispatchAnnotationsAction::class)->execute();
    $this->postJson($url.'/annotation-one/retry', ['threadId' => 'different'])->assertConflict();
    expect(Annotation::query()->firstOrFail()->task->target_thread_id)->toBe('thread-one');
});
