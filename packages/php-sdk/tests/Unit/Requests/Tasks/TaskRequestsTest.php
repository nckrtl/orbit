<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tasks\CancelSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\CancelTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CompleteTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\DestroySubtaskRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskAgentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskQuestionsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Requests\Tasks\SubtaskInput;
use Orbit\Sdk\Requests\Tasks\UpdateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskGroupRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskAssistanceResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;
use Orbit\Sdk\Responses\Tasks\TaskQuestionsResponse;
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
        'subtask cancel' => [new CancelSubtaskRequest(13, 57), Method::POST, '/api/v1/task-groups/13/tasks/57/cancel'],
        'comment create' => [new CreateTaskCommentRequest(13, 57, 'resolution', 'Done.', 'nick'), Method::POST, '/api/v1/task-groups/13/tasks/57/comments'],
        'comment list' => [new ListTaskCommentsRequest(13, 57), Method::GET, '/api/v1/task-groups/13/tasks/57/comments'],
        'question list' => [new ListTaskQuestionsRequest, Method::GET, '/api/v1/task-questions'],
        'agents' => [new ListTaskAgentsRequest(13), Method::GET, '/api/v1/task-groups/13/agents'],
    ]);

    it('sends create fields and omits only absent optional values', function (): void {
        expect(new CreateTaskGroupRequest(1, 'Title', 'Brief')->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief"}')
            ->and(new CreateTaskGroupRequest(1, 'Title', 'Brief', 'todo', false, tasks: [new SubtaskInput('One', 'First.')])->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief","status":"todo","notify_coder":false,"tasks":[{"title":"One","brief":"First."}]}')
            ->and(new CreateTaskGroupRequest(1, 'Title', 'Brief', tasks: [])->body()->all())
            ->toBe('{"project_id":1,"title":"Title","brief":"Brief","tasks":[]}')
            ->and(new CreateTaskGroupRequest(1, 'Title', 'Brief')->headers()->get('Content-Type'))
            ->toBe('application/json');
    });

    it('sends only the supplied update fields and an empty object when none is supplied', function (): void {
        expect(new UpdateTaskGroupRequest(13)->body()->all())->toBe('{}')
            ->and(new UpdateTaskGroupRequest(13, status: 'todo')->body()->all())->toBe('{"status":"todo"}')
            ->and(new UpdateTaskGroupRequest(13, '', 'Brief')->body()->all())->toBe('{"title":"","brief":"Brief"}')
            ->and(new UpdateSubtaskRequest(13, 57)->body()->all())->toBe('{}')
            ->and(new UpdateSubtaskRequest(13, 57, position: 2)->body()->all())->toBe('{"position":2}')
            ->and(new UpdateSubtaskRequest(13, 57, deliverables: [['id' => 'done', 'type' => 'review', 'description' => 'Done.']])->body()->all())
            ->toBe('{"deliverables":[{"id":"done","type":"review","description":"Done."}]}');
    });

    it('sends subtask and comment bodies', function (): void {
        $docs = ['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/a.md', 'change' => 'any'];
        $repro = ['id' => 'layout-repro', 'type' => 'test', 'description' => 'Fails before the fix', 'project' => 'apps/gateway', 'file' => 'tests/Feature/HomeScreenTest.php', 'name' => 'home screen layout', 'fails_on_base' => true];

        expect(new CreateSubtaskRequest(13, 'Title', 'Brief')->body()->all())
            ->toBe('{"title":"Title","brief":"Brief"}')
            ->and(new CreateSubtaskRequest(13, 'Title', 'Brief', [$docs])->body()->all())
            ->toBe('{"title":"Title","brief":"Brief","deliverables":[{"id":"docs","type":"file","description":"Docs","path":"docs\\/a.md","change":"any"}]}')
            ->and(new CreateSubtaskRequest(13, 'Title', 'Brief', [$repro])->body()->all())
            ->toContain('"fails_on_base":true')
            ->and(new UpdateSubtaskRequest(13, 57, deliverables: [$repro])->body()->all())
            ->toContain('"fails_on_base":true')
            ->and(new SubtaskInput('One', 'First.', [$docs])->toArray())
            ->toBe(['title' => 'One', 'brief' => 'First.', 'deliverables' => [$docs]])
            ->and(new CreateTaskCommentRequest(13, 57, 'resolution', 'Done.', 'nick')->body()->all())
            ->toBe('{"type":"resolution","body":"Done.","author":"nick"}')
            ->and(new CreateTaskCommentRequest(13, 57, 'assistance_requested', 'Stuck.', 'nick', 4)->body()->all())
            ->toBe('{"type":"assistance_requested","body":"Stuck.","author":"nick","agent_thread_id":4}');
    });

    it('puts list filters in the query and omits absent ones', function (): void {
        expect(new ListTaskGroupsRequest()->query()->all())->toBe([])
            ->and(new ListTaskGroupsRequest(4, 'backlog')->query()->all())->toBe(['project_id' => 4, 'status' => 'backlog'])
            ->and(new ListTaskQuestionsRequest()->query()->all())->toBe([])
            ->and(new ListTaskQuestionsRequest(4, 'contract_gap', 'answered', '2026-10-07T00:00:00Z')->query()->all())
            ->toBe(['project_id' => 4, 'cause' => 'contract_gap', 'status' => 'answered', 'since' => '2026-10-07T00:00:00Z']);
    });

    it('keeps status, task, and read requests bodyless', function (GatewayRequest $request): void {
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
        'status' => [new ShowTasksStatusRequest],
        'question list' => [new ListTaskQuestionsRequest],
        'cancel' => [new CancelTaskGroupRequest(13)],
        'complete' => [new CompleteTaskGroupRequest(13)],
        'destroy' => [new DestroySubtaskRequest(13, 57)],
        'subtask cancel' => [new CancelSubtaskRequest(13, 57)],
    ]);
});

describe('task responses from recorded Gateway fixtures', function (): void {
    it('maps each recorded success to its typed DTO', function (string $fixture, GatewayRequest $request, string $class): void {
        $response = task_fixture_send($fixture, $request);

        expect($response)->toBeInstanceOf($class)
            ->and($response->requestId)->toBe(task_request_id());
    })->with([
        'status' => ['tasks-status/enabled', new ShowTasksStatusRequest, TasksStatusResponse::class],
        'assisted status' => ['tasks-status/assistance', new ShowTasksStatusRequest, TasksStatusResponse::class],
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
        'subtask cancel' => ['tasks-subtask-cancel/cancelled', new CancelSubtaskRequest(1, 1), SubtaskResponse::class],
        'comment create' => ['tasks-comment-create/created', new CreateTaskCommentRequest(1, 1, 'resolution', 'Body', 'nick'), TaskCommentResponse::class],
        'comment list' => ['tasks-comment-list/default', new ListTaskCommentsRequest(1, 1), TaskCommentsResponse::class],
        'question list' => ['tasks-question-list/default', new ListTaskQuestionsRequest, TaskQuestionsResponse::class],
        'empty question list' => ['tasks-question-list/empty', new ListTaskQuestionsRequest, TaskQuestionsResponse::class],
        'agents' => ['tasks-agents/default', new ListTaskAgentsRequest(1), TaskAgentsResponse::class],
    ]);

    it('keeps the Gateway fields of a group and its ordered subtasks', function (): void {
        $group = task_fixture_send('tasks-show/default', new ShowTaskGroupRequest(1));

        expect($group)->toBeInstanceOf(TaskGroupResponse::class);
        assert($group instanceof TaskGroupResponse);

        expect($group->reference())->toBe('ORB-1')
            ->and($group->status)->toBe('backlog')
            ->and($group->project)->toBe('orbit')
            ->and($group->executionMode)->toBe('managed')
            ->and($group->assistanceRequested)->toBeFalse()
            ->and($group->assistanceKind)->toBeNull()
            ->and($group->assistanceQuestion)->toBeNull()
            ->and($group->assistanceReason)->toBeNull()
            ->and($group->questions)->toBe(0)
            ->and($group->escalations)->toBe(0)
            ->and($group->tasks[0]->assistanceRequested)->toBeFalse()
            ->and($group->tasks[0]->assistanceKind)->toBeNull()
            ->and($group->tasks[0]->assistanceQuestion)->toBeNull()
            ->and($group->tasks[0]->assistanceReason)->toBeNull()
            ->and($group->tasks[0]->questions)->toBe(0)
            ->and($group->tasks[0]->escalations)->toBe(0)
            ->and(array_map(static fn (SubtaskResponse $task): int => $task->position, $group->tasks))->toBe([1, 2])
            ->and($group->toArray())->not->toHaveKey('tasks.0.request_id')
            ->and($group->toArray()['tasks'][0])->not->toHaveKey('request_id')
            ->and($group->toArray()['request_id'])->toBe(task_request_id());
    });

    it('lists assisted groups on tasks status', function (): void {
        $enabled = task_fixture_send('tasks-status/enabled', new ShowTasksStatusRequest);
        $clear = task_fixture_send('tasks-status/enabled', new ShowTasksStatusRequest);
        $assisted = task_fixture_send('tasks-status/assistance', new ShowTasksStatusRequest);

        assert($enabled instanceof TasksStatusResponse && $clear instanceof TasksStatusResponse && $assisted instanceof TasksStatusResponse);

        expect($enabled->enabled)->toBeTrue()
            ->and($enabled->lastTickAt)->toBe('2026-09-23T10:00:00.000000Z')
            ->and($enabled->toArray()['last_tick_at'] ?? null)->toBe('2026-09-23T10:00:00.000000Z')
            ->and($assisted->lastTickAt)->toBeNull()
            ->and($assisted->toArray())->toHaveKey('last_tick_at', null)
            ->and($clear->assistance)->toBe([])
            ->and($clear->toArray()['assistance'])->toBe([])
            ->and(array_map(static fn (TaskAssistanceResponse $group): string => $group->reference(), $assisted->assistance ?? []))->toBe(['ORB-1', 'ORB-2'])
            ->and($assisted->toArray()['assistance'][0])->toMatchArray([
                'id' => 1,
                'project' => 'orbit',
                'project_code' => 'ORB',
                'title' => 'Blocked implementer',
                'status' => 'running',
                'assistance_kind' => null,
                'assistance_question' => null,
                'assistance_reason' => 'The implementer is blocked.',
            ])
            ->and($assisted->toArray()['assistance'][1])->toMatchArray([
                'title' => 'Settling question',
                'status' => 'settling',
                'assistance_kind' => null,
                'assistance_question' => null,
                'assistance_reason' => 'Which database should this use?',
            ])
            ->and($assisted->toArray()['assistance'])->toHaveCount(2);
    });

    it('refuses a tasks status whose last tick is not a string', function (): void {
        expect(static fn (): TasksStatusResponse => TasksStatusResponse::fromGatewayData(['enabled' => true, 'assistance' => [], 'last_tick_at' => 1_791_352_800], 'request-id'))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid tasks extension status.');
    });

    it('keeps an assistance request on the group and each subtask', function (): void {
        $mockClient = new MockClient([ShowTaskGroupRequest::class => MockResponse::make([
            'data' => [
                'id' => 4,
                'project_id' => 1,
                'title' => 'Stalled',
                'brief' => 'The group is waiting.',
                'status' => 'running',
                'assistance_requested' => true,
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
                'questions' => 3,
                'escalations' => 1,
                'tasks' => [
                    [
                        'id' => 8,
                        'task_group_id' => 4,
                        'position' => 1,
                        'title' => 'Blocked step',
                        'brief' => 'Needs a decision.',
                        'status' => 'running',
                        'assistance_requested' => true,
                        'assistance_kind' => 'direction',
                        'assistance_question' => 'Which database should this use?',
                        'assistance_reason' => 'The implementer is blocked.',
                        'questions' => 2,
                        'escalations' => 1,
                    ],
                    [
                        'id' => 9,
                        'task_group_id' => 4,
                        'position' => 2,
                        'title' => 'Later step',
                        'brief' => 'Not blocked.',
                        'status' => 'todo',
                        'assistance_requested' => false,
                        'assistance_reason' => null,
                    ],
                ],
            ],
            'meta' => ['request_id' => task_request_id()],
        ])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $group = $connector->send(new ShowTaskGroupRequest(4))->dto();

        expect($group)->toBeInstanceOf(TaskGroupResponse::class);
        assert($group instanceof TaskGroupResponse);
        expect($group->assistanceKind)->toBe('direction')
            ->and($group->assistanceQuestion)->toBe('Which database should this use?')
            ->and($group->questions)->toBe(3)
            ->and($group->escalations)->toBe(1)
            ->and($group->tasks[0]->questions)->toBe(2)
            ->and($group->tasks[0]->escalations)->toBe(1)
            ->and($group->tasks[1]->questions)->toBe(0)
            ->and($group->tasks[1]->escalations)->toBe(0)
            ->and($group->toArray())->toMatchArray([
                'assistance_requested' => true,
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
                'questions' => 3,
                'escalations' => 1,
            ])->and($group->toArray()['tasks'][0])->toMatchArray([
                'assistance_requested' => true,
                'assistance_kind' => 'direction',
                'assistance_question' => 'Which database should this use?',
                'assistance_reason' => 'The implementer is blocked.',
                'questions' => 2,
                'escalations' => 1,
            ])->and($group->toArray()['tasks'][1])->toMatchArray([
                'assistance_requested' => false,
                'assistance_kind' => null,
                'assistance_question' => null,
                'assistance_reason' => null,
                'questions' => 0,
                'escalations' => 0,
            ]);
    });

    it('reads the task question list', function (): void {
        $questions = task_fixture_send('tasks-question-list/default', new ListTaskQuestionsRequest);

        expect($questions)->toBeInstanceOf(TaskQuestionsResponse::class);
        assert($questions instanceof TaskQuestionsResponse);

        expect($questions->questions)->toHaveCount(2)
            ->and($questions->questions[0]->reference())->toBe('#13/57')
            ->and($questions->questions[0]->askedBy)->toBe('reviewer')
            ->and($questions->questions[0]->status)->toBe('answered')
            ->and($questions->questions[0]->cause)->toBe('contract_gap')
            ->and($questions->questions[0]->answer)->toBe('Follow ADR 0187.')
            ->and($questions->questions[0]->escalatedAt)->not->toBeNull()
            ->and($questions->questions[1]->askedBy)->toBe('implementer')
            ->and($questions->questions[1]->status)->toBe('open')
            ->and($questions->questions[1]->cause)->toBeNull()
            ->and($questions->questions[1]->answer)->toBeNull()
            ->and($questions->questions[1]->answeredBy)->toBeNull()
            ->and($questions->toArray()['questions'][0])->not->toHaveKey('request_id')
            ->and($questions->toArray()['questions'][0])->toMatchArray([
                'id' => 2,
                'task_id' => 13,
                'subtask_id' => 57,
                'attempt' => 2,
                'asked_by' => 'reviewer',
                'question' => 'Which ADR decides the database?',
                'status' => 'answered',
                'answered_by' => 'operator',
                'answer' => 'Follow ADR 0187.',
                'cause' => 'contract_gap',
            ]);

        $empty = task_fixture_send('tasks-question-list/empty', new ListTaskQuestionsRequest);

        expect($empty)->toBeInstanceOf(TaskQuestionsResponse::class);
        assert($empty instanceof TaskQuestionsResponse);
        expect($empty->questions)->toBe([])
            ->and($empty->toArray()['questions'])->toBe([]);
    });

    it('keeps the watched pull request separate from the published pull request', function (): void {
        $group = task_fixture_send('tasks-show/watched', new ShowTaskGroupRequest(1));
        assert($group instanceof TaskGroupResponse);

        expect($group->watchedPrUrl)->toBe('https://github.com/nckrtl/orbit/pull/451')
            ->and($group->watchedPrNumber)->toBe(451)
            ->and($group->watchedPrState)->toBe('open')
            ->and($group->prUrl)->toBeNull()
            ->and($group->toArray())->toMatchArray([
                'watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/451',
                'watched_pr_number' => 451,
                'watched_pr_state' => 'open',
                'pr_url' => null,
            ]);
        $unwatched = task_fixture_send('tasks-show/default', new ShowTaskGroupRequest(1));
        assert($unwatched instanceof TaskGroupResponse);
        expect($unwatched->watchedPrUrl)->toBeNull()
            ->and($unwatched->watchedPrNumber)->toBeNull()
            ->and($unwatched->watchedPrState)->toBeNull();
    });

    it('replays the complete recorded review fixup through existing brief and deliverables', function (): void {
        $group = task_fixture_send('tasks-show/review-fixup', new ShowTaskGroupRequest(1));
        assert($group instanceof TaskGroupResponse);
        $fixup = $group->tasks[2];
        $lines = array_filter(explode("\n", $fixup->brief), static fn (string $line): bool => str_starts_with($line, '> '));
        $source = json_decode(implode("\n", array_map(static fn (string $line): string => substr($line, 2), $lines)), true, flags: JSON_THROW_ON_ERROR);

        expect($fixup->title)->toBe('Address GitHub review findings from account 42')
            ->and($source['review']['reviewer_id'])->toBe(42)
            ->and($source['review']['id'])->toBe(101)
            ->and($source['review']['state'])->toBe('CHANGES_REQUESTED')
            ->and($source['review']['body'])->not->toBeEmpty()
            ->and($source['comments'])->toHaveCount(1)
            ->and($source['comments'][0]['body'])->not->toBeEmpty()
            ->and($source['comments'][0]['diff_hunk'])->not->toBeEmpty()
            ->and($fixup->brief)->toContain('Internal approval is not GitHub re-review.')
            ->and($fixup->deliverables[0]['id'])->toBe('review-findings')
            ->and($fixup->deliverables[0]['type'])->toBe('review')
            ->and($fixup->deliverables[1])->toMatchArray([
                'id' => 'project-check', 'type' => 'command', 'command' => 'composer check', 'directory' => '.',
            ])
            ->and($fixup->toArray()['brief'])->toBe($fixup->brief)
            ->and($fixup->toArray()['deliverables'])->toBe($fixup->deliverables);
    });

    it('keeps fails_on_base on a test deliverable', function (): void {
        $mockClient = new MockClient([CreateSubtaskRequest::class => MockResponse::make([
            'data' => [
                'id' => 1,
                'task_group_id' => 1,
                'position' => 1,
                'title' => 'Layout',
                'brief' => 'Fix the layout.',
                'status' => 'todo',
                'deliverables' => [[
                    'id' => 'layout-repro',
                    'type' => 'test',
                    'description' => 'Fails before the fix',
                    'fails_on_base' => true,
                ]],
            ],
            'meta' => ['request_id' => task_request_id()],
        ])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);
        $subtask = $connector->send(new CreateSubtaskRequest(1, 'Layout', 'Fix the layout.'))->dto();

        expect($subtask)->toBeInstanceOf(SubtaskResponse::class);
        assert($subtask instanceof SubtaskResponse);
        expect($subtask->deliverables[0]['fails_on_base'])->toBeTrue();
    });

    it('keeps comments newest first and agent errors', function (): void {
        $comments = task_fixture_send('tasks-comment-list/default', new ListTaskCommentsRequest(1, 1));
        $agents = task_fixture_send('tasks-agents/default', new ListTaskAgentsRequest(1));

        assert($comments instanceof TaskCommentsResponse && $agents instanceof TaskAgentsResponse);

        expect(array_map(static fn (TaskCommentResponse $comment): string => $comment->type, $comments->comments))
            ->toBe(['resolution', 'approved', 'ready_for_review'])
            ->and($comments->comments[1]->commitSha)->toBe('3f2a9c1e5b7d4f6a8c0e2b4d6f8a0c2e4b6d8f0a')
            ->and($agents->agents[1]->observationError)->toBe('T3 did not answer in time.')
            ->and($agents->agents[0]->inputTokens)->toBe(2100)
            ->and($agents->agents[0]->cachedInputTokens)->toBe(40100)
            ->and($agents->agents[0]->outputTokens)->toBe(6000)
            ->and($agents->agents[0]->modelCalls)->toBe(4)
            ->and($agents->agents[0]->peakContextTokens)->toBe(9800)
            ->and($agents->agents[1]->inputTokens)->toBeNull()
            ->and($agents->agents[1]->peakContextTokens)->toBeNull()
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
        'subtask not running' => ['tasks-subtask-cancel/not-running', new CancelSubtaskRequest(1, 1), 'tasks.subtask_not_running'],
        'subtask interrupt failed' => ['tasks-subtask-cancel/interrupt-failed', new CancelSubtaskRequest(2, 3), 'tasks.subtask_interrupt_failed'],
    ]);

    it('rejects malformed task records', function (GatewayRequest $request, mixed $data): void {
        $mockClient = new MockClient([$request::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => task_request_id()]])]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        expect(fn (): mixed => $connector->send($request)->dto())->toThrow(GatewayApiException::class);
    })->with([
        'status without enabled' => [new ShowTasksStatusRequest, ['enabled' => 'yes']],
        'status assistance is not a list' => [new ShowTasksStatusRequest, ['enabled' => true, 'assistance' => 'blocked']],
        'status assistance entry without an id' => [new ShowTasksStatusRequest, ['enabled' => true, 'assistance' => [['title' => 'Stalled', 'status' => 'running']]]],
        'group without title' => [new ShowTaskGroupRequest(1), ['id' => 1, 'project_id' => 1, 'brief' => 'B', 'status' => 'backlog', 'tasks' => []]],
        'group with scalar subtasks' => [new ShowTaskGroupRequest(1), ['id' => 1, 'project_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'backlog', 'tasks' => 'none']],
        'group list member without id' => [new ListTaskGroupsRequest, [['title' => 'T']]],
        'subtask without position' => [new CreateSubtaskRequest(1, 'T', 'B'), ['id' => 1, 'task_group_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'todo']],
        'subtask with a scalar deliverable' => [new CreateSubtaskRequest(1, 'T', 'B'), ['id' => 1, 'task_group_id' => 1, 'position' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'todo', 'deliverables' => ['docs']]],
        'comment without author' => [new CreateTaskCommentRequest(1, 1, 'resolution', 'B', 'nick'), ['id' => 1, 'task_group_id' => 1, 'task_id' => 1, 'type' => 'resolution', 'body' => 'B', 'posted_at' => 'now']],
        'agent without driver' => [new ListTaskAgentsRequest(1), [['id' => 1, 'task_group_id' => 1, 'role' => 'reviewer', 'external_id' => 'x']]],
        'group with a question count that is not an integer' => [new ShowTaskGroupRequest(1), ['id' => 1, 'project_id' => 1, 'title' => 'T', 'brief' => 'B', 'status' => 'backlog', 'questions' => 'many', 'tasks' => []]],
        'question without an id' => [new ListTaskQuestionsRequest, [['task_id' => 1, 'subtask_id' => 2, 'attempt' => 1, 'asked_by' => 'implementer', 'question' => 'Which database?', 'status' => 'open', 'asked_at' => '2026-10-07T00:00:00+00:00']]],
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

it('bounds observed sandbox power without inferring it from group status', function (mixed $value, ?string $expected): void {
    $group = TaskGroupResponse::fromGatewayData([
        'id' => 1, 'project_id' => 1, 'title' => 'Sandbox', 'brief' => 'Power is separate.',
        'status' => 'waiting_for_review', 'task_compute' => 'vm', 'questions' => 0, 'escalations' => 0,
        'sandbox_power' => $value,
    ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($group->sandboxPower)->toBe($expected)
        ->and($group->toArray()['sandbox_power'])->toBe($expected);
})->with([
    ['running', 'running'], ['stopped', 'stopped'], ['destroyed', 'destroyed'],
    [null, null], ['starting', null], [['running'], null], [true, null],
]);

it('transports preview omission and explicit booleans', function (?bool $preview): void {
    foreach ([new CreateTaskGroupRequest(1, 'Work', 'Brief', preview: $preview), new UpdateTaskGroupRequest(1, preview: $preview)] as $request) {
        $body = json_decode($request->body()->all(), true, flags: JSON_THROW_ON_ERROR);
        if ($preview === null) {
            expect($body)->not->toHaveKey('preview');
        } else {
            expect($body['preview'])->toBe($preview);
        }
    }
})->with([null, false, true]);
