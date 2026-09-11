<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Apps\CreateProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\CreateScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ListProcessDefinitionsRequest;
use Orbit\Sdk\Requests\Apps\ListScheduleDefinitionsRequest;
use Orbit\Sdk\Requests\Apps\RemoveProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\RemoveScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ReplaceProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ReplaceScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ShowProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ShowScheduleDefinitionRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('App runtime definition requests', function (): void {
    it('lists each command-safe definition collection with one request ID', function (
        string $requestClass,
        string $endpoint,
    ): void {
        $request = new $requestClass(7);
        $mock = new MockClient([
            $request::class => MockResponse::make([
                'data' => [runtime_definition_gateway_data(includeCommand: false)],
                'meta' => ['request_id' => runtime_definition_request_id()],
            ]),
        ]);

        $response = runtime_definition_connector($mock)->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe(Method::GET)
            ->and($request->resolveEndpoint())
            ->toBe($endpoint)
            ->and($pending?->body())
            ->toBeNull()
            ->and($pending?->headers()->all())
            ->not->toHaveKey('Content-Type')
            ->and($response)
            ->toBeInstanceOf(AppRuntimeDefinitionsResponse::class)
            ->and($response->requestId)
            ->toBe(runtime_definition_request_id())
            ->and($response->definitions)
            ->toHaveCount(1)
            ->and($response->definitions[0]->spec)
            ->not->toHaveKey('command')
            ->and($response->toArray())
            ->toBe([
                'definitions' => [runtime_definition_public_data(includeCommand: false)],
                'request_id' => runtime_definition_request_id(),
            ]);
    })->with([
        'processes' => [ListProcessDefinitionsRequest::class, '/api/v1/apps/7/process-definitions'],
        'Schedules' => [ListScheduleDefinitionsRequest::class, '/api/v1/apps/7/schedule-definitions'],
    ]);

    it('submits exact JSON content for create and full replacement', function (
        string $requestClass,
        bool $replacement,
        Method $method,
        string $endpoint,
    ): void {
        $definition = runtime_definition_json();
        $request = $replacement
            ? new $requestClass(7, runtime_definition_id(), $definition)
            : new $requestClass(7, $definition);
        $mock = new MockClient([
            $request::class => MockResponse::make(runtime_definition_envelope(), $method === Method::POST ? 201 : 200),
        ]);

        $response = runtime_definition_connector($mock)->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe($method)
            ->and($request->resolveEndpoint())
            ->toBe($endpoint)
            ->and($pending?->headers()->get('Content-Type'))
            ->toBe('application/json')
            ->and((string) $pending?->body())
            ->toBe($definition)
            ->and($response)
            ->toBeInstanceOf(AppRuntimeDefinitionResponse::class)
            ->and($response->requestId)
            ->toBe(runtime_definition_request_id())
            ->and($response->spec['command'] ?? null)
            ->toBe(['/usr/bin/php', 'artisan', 'queue:work']);
    })->with([
        'create process' => [
            CreateProcessDefinitionRequest::class,
            false,
            Method::POST,
            '/api/v1/apps/7/process-definitions',
        ],
        'replace process' => [
            ReplaceProcessDefinitionRequest::class,
            true,
            Method::PUT,
            '/api/v1/apps/7/process-definitions/'.runtime_definition_id(),
        ],
        'create Schedule' => [
            CreateScheduleDefinitionRequest::class,
            false,
            Method::POST,
            '/api/v1/apps/7/schedule-definitions',
        ],
        'replace Schedule' => [
            ReplaceScheduleDefinitionRequest::class,
            true,
            Method::PUT,
            '/api/v1/apps/7/schedule-definitions/'.runtime_definition_id(),
        ],
    ]);

    it('maps show and remove to bodyless typed item requests', function (
        string $requestClass,
        Method $method,
        string $endpoint,
    ): void {
        $request = new $requestClass(7, runtime_definition_id());
        $mock = new MockClient([
            $request::class => MockResponse::make(runtime_definition_envelope()),
        ]);

        $response = runtime_definition_connector($mock)->send($request)->dto();
        $pending = $mock->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe($method)
            ->and($request->resolveEndpoint())
            ->toBe($endpoint)
            ->and($pending?->body())
            ->toBeNull()
            ->and($pending?->headers()->all())
            ->not->toHaveKey('Content-Type')
            ->and($response)
            ->toBeInstanceOf(AppRuntimeDefinitionResponse::class)
            ->and($response->id)
            ->toBe(runtime_definition_id());
    })->with([
        'show process' => [
            ShowProcessDefinitionRequest::class,
            Method::GET,
            '/api/v1/apps/7/process-definitions/'.runtime_definition_id(),
        ],
        'remove process' => [
            RemoveProcessDefinitionRequest::class,
            Method::DELETE,
            '/api/v1/apps/7/process-definitions/'.runtime_definition_id(),
        ],
        'show Schedule' => [
            ShowScheduleDefinitionRequest::class,
            Method::GET,
            '/api/v1/apps/7/schedule-definitions/'.runtime_definition_id(),
        ],
        'remove Schedule' => [
            RemoveScheduleDefinitionRequest::class,
            Method::DELETE,
            '/api/v1/apps/7/schedule-definitions/'.runtime_definition_id(),
        ],
    ]);

    it('encodes definition identifiers exactly once as route segments', function (): void {
        expect(new ShowProcessDefinitionRequest(7, 'blue/green')->resolveEndpoint())
            ->toBe('/api/v1/apps/7/process-definitions/blue%2Fgreen')
            ->and(new RemoveScheduleDefinitionRequest(7, 'blue%2Fgreen')->resolveEndpoint())
            ->toBe('/api/v1/apps/7/schedule-definitions/blue%252Fgreen');
    });

    it('bounds malformed item fields to immutable typed values', function (): void {
        $response = AppRuntimeDefinitionResponse::fromGatewayData([
            'id' => 41,
            'app_id' => '7',
            'name' => ['worker'],
            'environments' => ['development', 5, 'production'],
            'spec' => ['runtime' => 'systemd', 0 => 'malformed'],
        ], runtime_definition_request_id());

        expect($response)
            ->toBeInstanceOf(AppRuntimeDefinitionResponse::class)
            ->and($response->id)
            ->toBe('')
            ->and($response->appId)
            ->toBe(0)
            ->and($response->name)
            ->toBe('')
            ->and($response->environments)
            ->toBe(['development', 'production'])
            ->and($response->spec)
            ->toBe(['runtime' => 'systemd'])
            ->and(fn (): mixed => $response->name = 'changed')
            ->toThrow(Error::class);
    });

    it('redacts credential-shaped specification values from item responses', function (): void {
        $data = runtime_definition_gateway_data();
        $data['spec']['environment'] = [
            'PUBLIC_NAME' => 'visible',
            'API_TOKEN' => 'definition-secret',
        ];

        $response = AppRuntimeDefinitionResponse::fromGatewayData($data, runtime_definition_request_id());

        expect($response->spec['environment'])
            ->toBe([
                'PUBLIC_NAME' => '[REDACTED]',
                'API_TOKEN' => '[REDACTED]',
            ])
            ->and(print_r($response, return: true))
            ->not->toContain('definition-secret');
    });

    it('preserves structured safe failures and their header request IDs', function (): void {
        $mock = new MockClient([
            ShowProcessDefinitionRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'process_definition.name_taken',
                    'message' => 'The process definition name is already in use.',
                    'details' => ['field' => 'name'],
                ],
            ], 409, ['X-Orbit-Request-Id' => runtime_definition_request_id()]),
        ]);

        try {
            runtime_definition_connector($mock)
                ->send(new ShowProcessDefinitionRequest(7, runtime_definition_id()))
                ->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('process_definition.name_taken')
                ->and($exception->getMessage())
                ->toBe('The process definition name is already in use.')
                ->and($exception->details())
                ->toBe(['field' => 'name'])
                ->and($exception->requestId())
                ->toBe(runtime_definition_request_id());
        }
    });

    it('marks submitted definition content as sensitive ingress', function (string $requestClass): void {
        $parameter = new ReflectionParameter([$requestClass, '__construct'], 'definition');

        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    })->with([
        CreateProcessDefinitionRequest::class,
        ReplaceProcessDefinitionRequest::class,
        CreateScheduleDefinitionRequest::class,
        ReplaceScheduleDefinitionRequest::class,
    ]);
});

function runtime_definition_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function runtime_definition_envelope(): array
{
    return [
        'data' => runtime_definition_gateway_data(),
        'meta' => ['request_id' => runtime_definition_request_id()],
    ];
}

/** @return array<string, mixed> */
function runtime_definition_gateway_data(bool $includeCommand = true): array
{
    $spec = [
        'runtime' => 'systemd',
        'restart_policy' => 'on-failure',
    ];

    if ($includeCommand) {
        $spec['command'] = ['/usr/bin/php', 'artisan', 'queue:work'];
    }

    return [
        'id' => runtime_definition_id(),
        'app_id' => 7,
        'name' => 'worker',
        'environments' => ['development', 'production'],
        'spec' => $spec,
    ];
}

/** @return array<string, mixed> */
function runtime_definition_public_data(bool $includeCommand = true): array
{
    return runtime_definition_gateway_data($includeCommand);
}

function runtime_definition_json(): string
{
    return <<<'JSON'
{"name":"worker","environments":["development","production"],"spec":{"runtime":"systemd","command":["/usr/bin/php","artisan","queue:work"],"restart_policy":"on-failure"}}
JSON;
}

function runtime_definition_id(): string
{
    return '0199cc58-b87b-7c45-9e52-e2b7fc495114';
}

function runtime_definition_request_id(): string
{
    return '0199cc58-d752-769a-bfa5-70caa94d975c';
}
