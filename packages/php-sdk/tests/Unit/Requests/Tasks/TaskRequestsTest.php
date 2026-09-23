<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tasks\CancelTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CompleteTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\DestroySubtaskRequest;
use Orbit\Sdk\Requests\Tasks\DisableTasksRequest;
use Orbit\Sdk\Requests\Tasks\EnableTasksRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskAgentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Requests\Tasks\SubtaskInput;
use Orbit\Sdk\Requests\Tasks\UpdateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskGroupRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('task transport', function (): void {
    it('addresses every tasks route', function (GatewayRequest $request, Method $method, string $endpoint): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'enable' => [new EnableTasksRequest, Method::POST, '/api/v1/tasks/enable'],
        'disable' => [new DisableTasksRequest, Method::POST, '/api/v1/tasks/disable'],
        'status' => [new ShowTasksStatusRequest, Method::GET, '/api/v1/tasks/status'],
        'list' => [new ListTaskGroupsRequest, Method::GET, '/api/v1/task-groups'],
        'create' => [new CreateTaskGroupRequest(1, 'Title', 'Brief'), Method::POST, '/api/v1/task-groups'],
        'show' => [new ShowTaskGroupRequest(13), Method::GET, '/api/v1/task-groups/13'],
        'update' => [new UpdateTaskGroupRequest(13), Method::PATCH, '/api/v1/task-groups/13'],
        'cancel' => [new CancelTaskGroupRequest(13), Method::POST, '/api/v1/task-groups/13/cancel'],
        'complete' => [new CompleteTaskGroupRequest(13), Method::POST, '/api/v1/task-groups/13/complete'],
        'subtask create' => [new CreateSubtaskRequest(13, 'Title', 'Brief'), Method::POST, '/api/v1/task-groups/13/tasks'],
        'subtask update' => [new UpdateSubtaskRequest(13, 57), Method::PATCH, '/api/v1/task-groups/13/tasks/57'],
        'subtask destroy' => [new DestroySubtaskRequest(13, 57), Method::DELETE, '/api/v1/task-groups/13/tasks/57'],
        'comment create' => [new CreateTaskCommentRequest(13, 57, 'resolution', 'Done.', 'nick'), Method::POST, '/api/v1/task-groups/13/tasks/57/comments'],
        'comment list' => [new ListTaskCommentsRequest(13, 57), Method::GET, '/api/v1/task-groups/13/tasks/57/comments'],
        'agents' => [new ListTaskAgentsRequest(13), Method::GET, '/api/v1/task-groups/13/agents'],
    ]);

    it('sends create fields and omits only absent optional values', function (): void {
        expect(new CreateTaskGroupRequest(1, 'Title', 'Brief')->body()->all())
            ->toBe('{"app_id":1,"title":"Title","brief":"Brief"}')
            ->and(new CreateTaskGroupRequest(1, 'Title', 'Brief', 'todo', false, [new SubtaskInput('One', 'First.')])->body()->all())
            ->toBe('{"app_id":1,"title":"Title","brief":"Brief","status":"todo","notify_coder":false,"tasks":[{"title":"One","brief":"First."}]}')
            ->and(new CreateTaskGroupRequest(1, 'Title', 'Brief', tasks: [])->body()->all())
            ->toBe('{"app_id":1,"title":"Title","brief":"Brief","tasks":[]}')
            ->and(new CreateTaskGroupRequest(1, 'Title', 'Brief')->headers()->get('Content-Type'))
            ->toBe('application/json');
    });

    it('sends only the supplied update fields and an empty object when none is supplied', function (): void {
        expect(new UpdateTaskGroupRequest(13)->body()->all())->toBe('{}')
            ->and(new UpdateTaskGroupRequest(13, status: 'todo')->body()->all())->toBe('{"status":"todo"}')
            ->and(new UpdateTaskGroupRequest(13, '', 'Brief')->body()->all())->toBe('{"title":"","brief":"Brief"}')
            ->and(new UpdateSubtaskRequest(13, 57)->body()->all())->toBe('{}')
            ->and(new UpdateSubtaskRequest(13, 57, position: 2)->body()->all())->toBe('{"position":2}');
    });

    it('sends subtask and comment bodies', function (): void {
        expect(new CreateSubtaskRequest(13, 'Title', 'Brief')->body()->all())
            ->toBe('{"title":"Title","brief":"Brief"}')
            ->and(new CreateTaskCommentRequest(13, 57, 'resolution', 'Done.', 'nick')->body()->all())
            ->toBe('{"type":"resolution","body":"Done.","author":"nick"}')
            ->and(new CreateTaskCommentRequest(13, 57, 'assistance_requested', 'Stuck.', 'nick', 4)->body()->all())
            ->toBe('{"type":"assistance_requested","body":"Stuck.","author":"nick","agent_thread_id":4}');
    });

    it('puts list filters in the query and omits absent ones', function (): void {
        expect(new ListTaskGroupsRequest()->query()->all())->toBe([])
            ->and(new ListTaskGroupsRequest(4, 'backlog')->query()->all())->toBe(['app_id' => 4, 'status' => 'backlog']);
    });

    it('keeps toggle, status, cancel, complete, and read requests bodyless', function (GatewayRequest $request): void {
        $mockClient = new MockClient([MockResponse::make(['data' => ['enabled' => true, 'id' => 1], 'meta' => ['request_id' => task_request_id()]])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send($request);
        } catch (GatewayApiException) {
            // Only the outgoing request matters here.
        }

        expect($request)->not->toBeInstanceOf(HasBody::class)
            ->and((string) $mockClient->getLastPendingRequest()?->createPsrRequest()->getBody())->toBeEmpty();
    })->with([
        'enable' => [new EnableTasksRequest],
        'disable' => [new DisableTasksRequest],
        'status' => [new ShowTasksStatusRequest],
        'cancel' => [new CancelTaskGroupRequest(13)],
        'complete' => [new CompleteTaskGroupRequest(13)],
        'destroy' => [new DestroySubtaskRequest(13, 57)],
    ]);
});

describe('task responses from recorded Gateway fixtures', function (): void {
    it('maps each recorded success to its typed DTO', function (string $fixture, GatewayRequest $request, string $class): void {
        $response = task_fixture_send($fixture, $request);

        expect($response)->toBeInstanceOf($class)
            ->and($response->requestId)->toBe(task_request_id());
    })->with([
        'enable' => ['tasks-enable/enabled', new EnableTasksRequest, TasksStatusResponse::class],
        'disable' => ['tasks-disable/disabled', new DisableTasksRequest, TasksStatusResponse::class],
        'status' => ['tasks-status/enabled', new ShowTasksStatusRequest, TasksStatusResponse::class],
        'list' => ['tasks-list/default', new ListTaskGroupsRequest, TaskGroupsResponse::class],
        'empty list' => ['tasks-list/empty', new ListTaskGroupsRequest, TaskGroupsResponse::class],
        'create' => ['tasks-create/created', new CreateTaskGroupRequest(1, 'Add the tasks CLI', 'Brief'), TaskGroupResponse::class],
        'show' => ['tasks-show/default', new ShowTaskGroupRequest(1), TaskGroupResponse::class],
        'update' => ['tasks-update/updated', new UpdateTaskGroupRequest(1, 'Add the tasks command family'), TaskGroupResponse::class],
        'cancel' => ['tasks-cancel/cancelled', new CancelTaskGroupRequest(1), TaskGroupResponse::class],
        'complete' => ['tasks-complete/completed', new CompleteTaskGroupRequest(2), TaskGroupResponse::class],
        'subtask create' => ['tasks-subtask-create/created', new CreateSubtaskRequest(1, 'Document the commands', 'Brief'), SubtaskResponse::class],
        'subtask update' => ['tasks-subtask-update/updated', new UpdateSubtaskRequest(1, 1, position: 2), SubtaskResponse::class],
        'subtask destroy' => ['tasks-subtask-destroy/destroyed', new DestroySubtaskRequest(1, 1), SubtaskResponse::class],
        'comment create' => ['tasks-comment-create/created', new CreateTaskCommentRequest(1, 1, 'resolution', 'Body', 'nick'), TaskCommentResponse::class],
        'comment list' => ['tasks-comment-list/default', new ListTaskCommentsRequest(1, 1), TaskCommentsResponse::class],
        'agents' => ['tasks-agents/default', new ListTaskAgentsRequest(1), TaskAgentsResponse::class],
    ]);

    it('keeps the Gateway fields of a group and its ordered subtasks', function (): void {
        $group = task_fixture_send('tasks-show/default', new ShowTaskGroupRequest(1));

        expect($group)->toBeInstanceOf(TaskGroupResponse::class);
        assert($group instanceof TaskGroupResponse);

        expect($group->reference())->toBe('ORB-1')
            ->and($group->status)->toBe('backlog')
            ->and($group->app)->toBe('orbit')
            ->and($group->executionMode)->toBe('managed')
            ->and(array_map(static fn (SubtaskResponse $task): int => $task->position, $group->tasks))->toBe([1, 2])
            ->and($group->toArray())->not->toHaveKey('tasks.0.request_id')
            ->and($group->toArray()['tasks'][0])->not->toHaveKey('request_id')
            ->and($group->toArray()['request_id'])->toBe(task_request_id());
    });

    it('keeps comments newest first and agent errors', function (): void {
        $comments = task_fixture_send('tasks-comment-list/default', new ListTaskCommentsRequest(1, 1));
        $agents = task_fixture_send('tasks-agents/default', new ListTaskAgentsRequest(1));

        assert($comments instanceof TaskCommentsResponse && $agents instanceof TaskAgentsResponse);

        expect(array_map(static fn (TaskCommentResponse $comment): string => $comment->type, $comments->comments))
            ->toBe(['resolution', 'approved', 'ready_for_review'])
            ->and($comments->comments[1]->commitSha)->toBe('3f2a9c1e5b7d4f6a8c0e2b4d6f8a0c2e4b6d8f0a')
            ->and($agents->agents[1]->observationError)->toBe('T3 did not answer in time.')
            ->and($agents->toArray()['agents'][0])->not->toHaveKey('request_id');
    });

    it('preserves the Gateway error code of a recorded refusal', function (string $fixture, GatewayRequest $request, string $code): void {
        try {
            task_fixture_send($fixture, $request);
            $this->fail('The refusal did not throw.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code);
        }
    })->with([
        'no subtasks' => ['tasks-create/no-subtasks', new CreateTaskGroupRequest(1, 'Empty', 'No subtasks.', 'todo'), 'tasks.no_subtasks'],
        'not in backlog' => ['tasks-update/not-in-backlog', new UpdateTaskGroupRequest(1, 'Too late'), 'tasks.not_in_backlog'],
    ]);

    it('rejects malformed task records', function (GatewayRequest $request, mixed $data): void {
        $mockClient = new MockClient([$request::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => task_request_id()]])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        expect(fn (): mixed => $connector->send($request)->dto())->toThrow(GatewayApiException::class);
    })->with([
        'status without enabled' => [new ShowTasksStatusRequest, ['enabled' => 'yes']],
        'group without title' => [new ShowTaskGroupRequest(1), ['id' => 1, 'app_id' => 1, 'brief' => 'B', 'status' => 'backlog', 'tasks' => []]],
        'group with scalar subtasks' => [new ShowTaskGroupRequest(1), ['id' => 1, 'app_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'backlog', 'tasks' => 'none']],
        'group list member without id' => [new ListTaskGroupsRequest, [['title' => 'T']]],
        'subtask without position' => [new CreateSubtaskRequest(1, 'T', 'B'), ['id' => 1, 'task_group_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'todo']],
        'comment without author' => [new CreateTaskCommentRequest(1, 1, 'resolution', 'B', 'nick'), ['id' => 1, 'task_group_id' => 1, 'task_id' => 1, 'type' => 'resolution', 'body' => 'B', 'posted_at' => 'now']],
        'agent without driver' => [new ListTaskAgentsRequest(1), [['id' => 1, 'task_group_id' => 1, 'role' => 'reviewer', 'external_id' => 'x']]],
    ]);
});

function task_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** Replays a recorded Gateway response from fixtures/tasks and returns the request's DTO. */
function task_fixture_send(string $fixture, GatewayRequest $request): object
{
    $recorded = json_decode(
        (string) file_get_contents(dirname(__DIR__, levels: 4)."/fixtures/tasks/{$fixture}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $mockClient = new MockClient([$request::class => MockResponse::make($recorded['body'], $recorded['status'])]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    $dto = $connector->send($request)->dto();
    assert(is_object($dto));

    return $dto;
}
