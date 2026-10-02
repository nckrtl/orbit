<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\DestroyTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskDefinitionsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskDefinitionRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('task definition requests', function (): void {
    it('addresses the five definition routes', function (GatewayRequest $request, Method $method, string $endpoint): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'list' => [new ListTaskDefinitionsRequest, Method::GET, '/api/v1/task-definitions'],
        'show' => [new ShowTaskDefinitionRequest(4, 'build-feature'), Method::GET, '/api/v1/projects/4/task-definitions/build-feature'],
        'create' => [new CreateTaskDefinitionRequest(4, '{}'), Method::POST, '/api/v1/projects/4/task-definitions'],
        'update' => [new UpdateTaskDefinitionRequest(4, 'build-feature', '{}'), Method::PUT, '/api/v1/projects/4/task-definitions/build-feature'],
        'destroy' => [new DestroyTaskDefinitionRequest(4, 'build-feature'), Method::DELETE, '/api/v1/projects/4/task-definitions/build-feature'],
    ]);

    it('puts the project filter in the query and omits it when absent', function (): void {
        expect(new ListTaskDefinitionsRequest()->query()->all())->toBe([])
            ->and(new ListTaskDefinitionsRequest(4)->query()->all())->toBe(['project_id' => 4]);
    });

    it('sends the caller JSON unchanged for create and update', function (GatewayRequest $request, Method $method, string $endpoint): void {
        $mock = new MockClient([
            $request::class => MockResponse::make(definition_request_envelope(), $method === Method::POST ? 201 : 200),
        ]);

        $response = definition_request_connector($mock)->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($pending?->headers()->get('Content-Type'))->toBe('application/json')
            ->and((string) $pending?->body())->toBe(definition_request_json())
            ->and($request->resolveEndpoint())->toBe($endpoint)
            ->and($response)->toBeInstanceOf(TaskDefinitionResponse::class)
            ->and($response->name)->toBe('build-feature')
            ->and($response->requestId)->toBe(definition_request_id());
    })->with([
        'create' => [
            new CreateTaskDefinitionRequest(4, definition_request_json()),
            Method::POST,
            '/api/v1/projects/4/task-definitions',
        ],
        'update' => [
            new UpdateTaskDefinitionRequest(4, 'build-feature', definition_request_json()),
            Method::PUT,
            '/api/v1/projects/4/task-definitions/build-feature',
        ],
    ]);

    it('keeps list, show, and destroy bodyless', function (GatewayRequest $request): void {
        $mock = new MockClient([
            $request::class => MockResponse::make([
                'data' => $request instanceof ListTaskDefinitionsRequest ? [definition_request_record()] : definition_request_record(),
                'meta' => ['request_id' => definition_request_id()],
            ]),
        ]);

        definition_request_connector($mock)->send($request);

        expect($mock->getLastPendingRequest()?->body())->toBeNull()
            ->and($mock->getLastPendingRequest()?->headers()->all())->not->toHaveKey('Content-Type');
    })->with([
        'list' => [new ListTaskDefinitionsRequest(4)],
        'show' => [new ShowTaskDefinitionRequest(4, 'build-feature')],
        'destroy' => [new DestroyTaskDefinitionRequest(4, 'build-feature')],
    ]);

    it('encodes a definition name once', function (): void {
        expect(new ShowTaskDefinitionRequest(4, 'blue/green')->resolveEndpoint())
            ->toBe('/api/v1/projects/4/task-definitions/blue%2Fgreen')
            ->and(new DestroyTaskDefinitionRequest(4, 'blue%2Fgreen')->resolveEndpoint())
            ->toBe('/api/v1/projects/4/task-definitions/blue%252Fgreen');
    });

    it('maps recorded success responses and keeps an empty object empty', function (): void {
        $created = definition_fixture_send('tasks-definition-create/created', new CreateTaskDefinitionRequest(1, definition_request_json()));
        $listed = definition_fixture_send('tasks-definition-list/default', new ListTaskDefinitionsRequest(1));
        $empty = definition_fixture_send('tasks-definition-list/empty', new ListTaskDefinitionsRequest);
        $shown = definition_fixture_send('tasks-definition-show/default', new ShowTaskDefinitionRequest(1, 'build-feature'));
        $updated = definition_fixture_send('tasks-definition-update/updated', new UpdateTaskDefinitionRequest(1, 'build-feature', definition_request_json()));
        $destroyed = definition_fixture_send('tasks-definition-destroy/destroyed', new DestroyTaskDefinitionRequest(1, 'build-feature'));

        expect($created)->toBeInstanceOf(TaskDefinitionResponse::class)
            ->and($created->projectId)->toBe(1)
            ->and($created->status)->toBe('backlog')
            ->and($created->schedule)->toBeNull()
            ->and($created->subtasks)->toBe([['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent']])
            ->and($created->requestId)->toBe(definition_request_id())
            ->and($created->toArray()['request_id'])->toBe(definition_request_id())
            ->and($listed)->toBeInstanceOf(TaskDefinitionsResponse::class)
            ->and($listed->definitions)->toHaveCount(1)
            ->and($listed->toArray()['task_definitions'][0])->not->toHaveKey('request_id')
            ->and($listed->toArray()['request_id'])->toBe(definition_request_id())
            ->and($empty)->toBeInstanceOf(TaskDefinitionsResponse::class)
            ->and($empty->definitions)->toBe([])
            ->and($shown)->toBeInstanceOf(TaskDefinitionResponse::class)
            ->and($shown->title)->toBe('Build a feature')
            ->and($updated)->toBeInstanceOf(TaskDefinitionResponse::class)
            ->and($updated->title)->toBe('Build the feature')
            ->and($updated->status)->toBe('todo')
            ->and($destroyed)->toBeInstanceOf(TaskDefinitionResponse::class)
            ->and($destroyed->name)->toBe('build-feature');

        $withObjects = TaskDefinitionResponse::fromGatewayData([
            'project_id' => 1,
            'name' => 'build-feature',
            'title' => 'Build a feature',
            'brief' => 'Document and build it.',
            'parameters' => [],
            'status' => 'backlog',
            'schedule' => ['cron' => '0 3 * * 1', 'values' => []],
            'phases' => [],
            'subtasks' => [[
                'key' => 'ship',
                'title' => 'Ship it',
                'kind' => 'action',
                'operation' => 'instance:deploy',
                'arguments' => [],
                'routes' => [],
            ]],
        ], definition_request_id());

        expect(json_encode($withObjects->toArray(), JSON_THROW_ON_ERROR))
            ->toContain('"values":{}')
            ->toContain('"arguments":{}')
            ->toContain('"routes":{}');
    });

    it('keeps a nested empty object through show and update', function (): void {
        $body = <<<'JSON'
{"data":{"project_id":1,"name":"build-feature","title":"Build a feature","brief":"Document and build it.","parameters":[{"name":"tuning","type":"text","required":false,"default":{"options":{},"flags":[]}}],"status":"backlog","schedule":null,"phases":[],"subtasks":[{"key":"ship","title":"Ship it","kind":"action","operation":"instance:deploy","arguments":{"options":{},"flags":[]}}]},"meta":{"request_id":"0198e15c-bf97-7c23-8f1f-61b8fe67a844"}}
JSON;
        $mock = new MockClient([
            ShowTaskDefinitionRequest::class => MockResponse::make($body, 200),
            UpdateTaskDefinitionRequest::class => MockResponse::make($body, 200),
        ]);
        $connector = definition_request_connector($mock);
        $shown = $connector->send(new ShowTaskDefinitionRequest(1, 'build-feature'))->dto();

        expect($shown)->toBeInstanceOf(TaskDefinitionResponse::class);
        assert($shown instanceof TaskDefinitionResponse);

        $shownJson = json_encode($shown->toArray(), JSON_THROW_ON_ERROR);
        $document = json_decode($shownJson, false, 512, JSON_THROW_ON_ERROR);
        unset($document->request_id, $document->project_id);
        $updateJson = json_encode($document, JSON_THROW_ON_ERROR);
        $updated = $connector->send(new UpdateTaskDefinitionRequest(1, 'build-feature', $updateJson))->dto();

        expect($shownJson)->toContain('"default":{"options":{},"flags":[]}')
            ->and($shownJson)->toContain('"arguments":{"options":{},"flags":[]}')
            ->and((string) $mock->getLastRequest()?->body())->toBe($updateJson)
            ->and($updated)->toBeInstanceOf(TaskDefinitionResponse::class);
        assert($updated instanceof TaskDefinitionResponse);
        expect(json_encode($updated->toArray(), JSON_THROW_ON_ERROR))
            ->toContain('"default":{"options":{},"flags":[]}')
            ->toContain('"arguments":{"options":{},"flags":[]}');
    });

    it('preserves a refused invalid definition and a name that already exists', function (string $fixture, GatewayRequest $request, string $code, array $details): void {
        try {
            definition_fixture_send($fixture, $request);
            throw new RuntimeException('The refusal did not throw.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe($code)
                ->and($exception->details())->toBe($details)
                ->and($exception->getMessage())->not->toBe('');
        }
    })->with([
        'invalid' => [
            'tasks-definition-create/invalid',
            new CreateTaskDefinitionRequest(1, definition_request_json()),
            'tasks.definition_invalid',
            ['rules' => [['rule' => 'kind', 'subtask' => 'docs']]],
        ],
        'exists' => [
            'tasks-definition-create/exists',
            new CreateTaskDefinitionRequest(1, definition_request_json()),
            'tasks.definition_exists',
            ['name' => 'build-feature'],
        ],
        'missing' => [
            'tasks-definition-show/missing',
            new ShowTaskDefinitionRequest(1, 'missing'),
            'http.404',
            [],
        ],
    ]);

    it('marks the submitted definition as sensitive ingress', function (string $requestClass): void {
        $parameter = new ReflectionParameter([$requestClass, '__construct'], 'definition');

        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    })->with([
        CreateTaskDefinitionRequest::class,
        UpdateTaskDefinitionRequest::class,
    ]);
});

function definition_request_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

function definition_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function definition_request_json(): string
{
    return '{"name":"build-feature","title":"Build a feature","brief":"Document and build it.","parameters":[],"status":"backlog","subtasks":[{"key":"docs","title":"Write the docs","kind":"agent"}]}';
}

/** @return array<string, mixed> */
function definition_request_record(): array
{
    return [
        'project_id' => 1,
        'name' => 'build-feature',
        'title' => 'Build a feature',
        'brief' => 'Document and build it.',
        'parameters' => [],
        'status' => 'backlog',
        'schedule' => null,
        'phases' => [],
        'subtasks' => [
            ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent'],
        ],
    ];
}

/** @return array<string, mixed> */
function definition_request_envelope(): array
{
    return [
        'data' => definition_request_record(),
        'meta' => ['request_id' => definition_request_id()],
    ];
}

function definition_fixture_send(string $fixture, GatewayRequest $request): object
{
    $recorded = json_decode(
        (string) file_get_contents(dirname(__DIR__)."/fixtures/tasks/{$fixture}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $mock = new MockClient([
        $request::class => MockResponse::make($recorded['body'], $recorded['status']),
    ]);

    $dto = definition_request_connector($mock)->send($request)->dto();
    assert(is_object($dto));

    return $dto;
}
