<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\AddProcessRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Requests\Processes\RemoveProcessRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessLogsResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('adds a process with the explicit minimal runtime contract', function (): void {
    $mock = new MockClient([
        AddProcessRequest::class => MockResponse::make(process_envelope(), 201),
    ]);
    $request = new AddProcessRequest(
        targetType: 'instance',
        targetId: 7,
        name: 'redis',
        runtime: 'docker',
        command: ['redis-server'],
        image: 'redis:8-alpine',
        workingDirectory: '/data',
        environment: ['APP_MODE' => 'test'],
        ports: ['127.0.0.1:6380:6379/tcp'],
        volumes: [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        restartPolicy: 'unless-stopped',
        start: true,
    );
    $response = process_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/processes')
        ->and($request->body()->all())
        ->toMatchArray([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'redis',
            'runtime' => 'docker',
            'command' => ['redis-server'],
            'image' => 'redis:8-alpine',
            'working_directory' => '/data',
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
            'restart_policy' => 'unless-stopped',
            'start' => true,
        ])
        ->and($response)
        ->toBeInstanceOf(ProcessResponse::class)
        ->and($response->requestId)
        ->toBe(process_request_id());
});

it('forwards every explicit process field without applying runtime policy', function (): void {
    $request = new AddProcessRequest(
        targetType: 'instance',
        targetId: 7,
        name: 'worker',
        runtime: 'systemd',
        command: ['php', 'artisan', 'queue:work'],
        image: 'orbit-worker:latest',
        workingDirectory: '/srv/orbit',
        environment: ['APP_MODE' => 'production'],
        ports: ['127.0.0.1:9080:8080/tcp'],
        volumes: [['source' => 'orbit-data', 'target' => '/data', 'read_only' => true]],
        restartPolicy: 'always',
        start: true,
    );

    expect($request->body()->all())->toBe([
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'worker',
        'runtime' => 'systemd',
        'command' => ['php', 'artisan', 'queue:work'],
        'restart_policy' => 'always',
        'start' => true,
        'environment' => ['APP_MODE' => 'production'],
        'ports' => ['127.0.0.1:9080:8080/tcp'],
        'volumes' => [['source' => 'orbit-data', 'target' => '/data', 'read_only' => true]],
        'image' => 'orbit-worker:latest',
        'working_directory' => '/srv/orbit',
    ]);
});

it('omits every absent optional process field without applying runtime policy', function (): void {
    $request = new AddProcessRequest(
        targetType: 'instance',
        targetId: 7,
        name: 'worker',
        runtime: 'systemd',
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
    );

    expect($request->body()->all())->toBe([
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'worker',
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
        'restart_policy' => 'never',
        'start' => false,
    ]);
});

it('preserves explicitly supplied empty process collections', function (): void {
    $request = new AddProcessRequest(
        targetType: 'instance',
        targetId: 7,
        name: 'redis',
        runtime: 'docker',
        command: ['redis-server'],
        environment: [],
        ports: [],
        volumes: [],
    );

    expect($request->body()->all())->toBe([
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'redis',
        'runtime' => 'docker',
        'command' => ['redis-server'],
        'restart_policy' => 'never',
        'start' => false,
        'environment' => [],
        'ports' => [],
        'volumes' => [],
    ]);
});

it('preserves an omitted optional volume read-only flag', function (): void {
    $request = new AddProcessRequest(
        targetType: 'instance',
        targetId: 7,
        name: 'redis',
        runtime: 'docker',
        command: ['redis-server'],
        volumes: [['source' => 'redis-data', 'target' => '/data']],
    );

    expect($request->body()->all())->toMatchArray([
        'volumes' => [['source' => 'redis-data', 'target' => '/data']],
    ]);
});

it('lists only one target process collection', function (): void {
    $mock = new MockClient([
        ListProcessesRequest::class => MockResponse::make([
            'data' => [process_gateway_data()],
            'meta' => ['request_id' => process_request_id()],
        ]),
    ]);
    $request = new ListProcessesRequest('instance', 7);
    $response = process_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/processes')
        ->and($request->query()->all())
        ->toBe(['target_type' => 'instance', 'target_id' => 7])
        ->and($response)
        ->toBeInstanceOf(ProcessesResponse::class)
        ->and($response->processes)
        ->toHaveCount(1)
        ->and($response->processes[0]->runtimeConfig)
        ->toBe([
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [],
        ])
        ->not->toHaveKey('environment');
});

it('bounds runtime configuration to a string-keyed response map', function (): void {
    $data = process_gateway_data();
    $data['runtime_config'] = [
        'image' => 'redis:8-alpine',
        0 => 'malformed',
    ];

    $response = ProcessResponse::fromGatewayData($data, process_request_id());

    expect($response->runtimeConfig)->toBe(['image' => 'redis:8-alpine']);
});

it('maps lifecycle and remove endpoints to one typed process response', function (
    string $requestClass,
    Method $method,
    string $endpoint,
): void {
    $request = new $requestClass(12);
    $mock = new MockClient([
        $request::class => MockResponse::make(process_envelope()),
    ]);
    $response = process_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe($method)
        ->and($request->resolveEndpoint())
        ->toBe($endpoint)
        ->and($response)
        ->toBeInstanceOf(ProcessResponse::class);
})->with([
    'start' => [StartProcessRequest::class, Method::POST, '/api/v1/processes/12/start'],
    'stop' => [StopProcessRequest::class, Method::POST, '/api/v1/processes/12/stop'],
    'restart' => [RestartProcessRequest::class, Method::POST, '/api/v1/processes/12/restart'],
    'remove' => [RemoveProcessRequest::class, Method::DELETE, '/api/v1/processes/12'],
]);

it('sends lifecycle actions without a JSON request body', function (string $requestClass): void {
    $request = new $requestClass(12);
    $mock = new MockClient([
        $request::class => MockResponse::make(process_envelope()),
    ]);

    process_connector($mock)->send($request);

    $pendingRequest = $mock->getLastPendingRequest();

    expect($pendingRequest?->body())
        ->toBeNull()
        ->and($pendingRequest?->headers()->all())
        ->not
        ->toHaveKey('Content-Type')
        ->and((string) $pendingRequest?->createPsrRequest()->getBody())
        ->toBeEmpty();
})->with([
    'start' => [StartProcessRequest::class],
    'stop' => [StopProcessRequest::class],
    'restart' => [RestartProcessRequest::class],
]);

it('returns one bounded non-streaming log tail', function (): void {
    $mock = new MockClient([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' => "Authorization: Bearer super-secret-token\n",
            ],
            'meta' => ['request_id' => process_request_id()],
        ]),
    ]);
    $request = new ProcessLogsRequest(12, 25);
    $response = process_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/processes/12/logs')
        ->and($request->query()->all())
        ->toBe(['lines' => 25])
        ->and($response)
        ->toBeInstanceOf(ProcessLogsResponse::class)
        ->and($response->logs)
        ->toBe("Authorization: [REDACTED]\n")
        ->not->toContain('super-secret-token')->and($response->toArray())
        ->not->toHaveKey('follow');
});

function process_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function process_envelope(): array
{
    return [
        'data' => process_gateway_data(),
        'meta' => ['request_id' => process_request_id()],
    ];
}

/** @return array<string, mixed> */
function process_gateway_data(): array
{
    return [
        'id' => 12,
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'redis',
        'runtime' => 'docker',
        'working_directory' => '/data',
        'runtime_config' => [
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'running',
        'status' => 'active',
        'runtime_status' => 'running',
        'failed_step' => null,
        'error_code' => null,
    ];
}

function process_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
