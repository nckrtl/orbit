<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Herdr\CreateHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\DestroyHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\IssueObservationGrantRequest;
use Orbit\Sdk\Requests\Herdr\ListHerdrSessionsRequest;
use Orbit\Sdk\Requests\Herdr\RestartHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\ShowHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;
use Orbit\Sdk\Responses\Herdr\HerdrSessionsResponse;
use Orbit\Sdk\Responses\Herdr\ObservationGrantResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('adds a named Herdr session with the explicit Node selector', function (): void {
    $mock = new MockClient([
        CreateHerdrSessionRequest::class => MockResponse::make(herdr_session_envelope(), 201),
    ]);
    $request = new CreateHerdrSessionRequest(
        nodeId: 4,
        session: 'commander-tasks',
        user: 'nckrtl',
        publishObserver: true,
    );
    $response = herdr_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/herdr/sessions')
        ->and($request->body()->all())
        ->toBe([
            'node_id' => 4,
            'session' => 'commander-tasks',
            'user' => 'nckrtl',
            'publish_observer' => true,
        ])
        ->and($response)
        ->toBeInstanceOf(HerdrSessionResponse::class)
        ->and($response->node)
        ->toBe('beast')
        ->and($response->processId)
        ->toBe(481)
        ->and($response->observerUrl)
        ->toBe('wss://commander-tasks.herdr.beast.orbit')
        ->and($response->herdrVersion)
        ->toBe('0.9.0')
        ->and($response->protocol)
        ->toBe(22)
        ->and($response->health)
        ->toBe([
            'process' => 'healthy',
            'listener' => 'healthy',
            'session' => 'healthy',
        ])
        ->and($response->requestId)
        ->toBe(herdr_request_id());
});

it('preserves an explicit false observer publication flag', function (): void {
    $request = new CreateHerdrSessionRequest(
        nodeId: 4,
        session: 'commander-tasks',
        user: 'nckrtl',
        publishObserver: false,
    );

    expect($request->body()->all())->toBe([
        'node_id' => 4,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => false,
    ]);
});

it('lists Herdr sessions for one Node', function (): void {
    $mock = new MockClient([
        ListHerdrSessionsRequest::class => MockResponse::make([
            'data' => [herdr_session_gateway_data()],
            'meta' => ['request_id' => herdr_request_id()],
        ]),
    ]);
    $request = new ListHerdrSessionsRequest(4);
    $response = herdr_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/herdr/sessions')
        ->and($request->query()->all())
        ->toBe(['node_id' => 4])
        ->and($response)
        ->toBeInstanceOf(HerdrSessionsResponse::class)
        ->and($response->sessions)
        ->toHaveCount(1)
        ->and($response->sessions[0]->session)
        ->toBe('commander-tasks')
        ->and($response->toArray()['sessions'][0])
        ->not->toHaveKey('request_id');
});

it('maps show, restart, and remove to one typed Herdr session response', function (
    string $requestClass,
    Method $method,
    string $endpoint,
    array $body,
): void {
    $request = $requestClass === RestartHerdrSessionRequest::class
        || $requestClass === DestroyHerdrSessionRequest::class
        ? new $requestClass(12, true)
        : new $requestClass(12);
    $mock = new MockClient([
        $request::class => MockResponse::make(herdr_session_envelope()),
    ]);
    $response = herdr_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe($method)
        ->and($request->resolveEndpoint())
        ->toBe($endpoint)
        ->and($response)
        ->toBeInstanceOf(HerdrSessionResponse::class);

    if ($body !== []) {
        expect($request->body()->all())->toBe($body);
    }
})->with([
    'show' => [ShowHerdrSessionRequest::class, Method::GET, '/api/v1/herdr/sessions/12', []],
    'restart' => [
        RestartHerdrSessionRequest::class,
        Method::POST,
        '/api/v1/herdr/sessions/12/restart',
        ['handoff' => true],
    ],
    'remove' => [
        DestroyHerdrSessionRequest::class,
        Method::DELETE,
        '/api/v1/herdr/sessions/12',
        ['accept_termination' => true],
    ],
]);

it('preserves omitted Herdr lifecycle flags as explicit false', function (): void {
    expect((new RestartHerdrSessionRequest(12))->body()->all())
        ->toBe(['handoff' => false])
        ->and((new DestroyHerdrSessionRequest(12))->body()->all())
        ->toBe(['accept_termination' => false]);
});

it('issues a scoped observation grant without exposing a raw token field', function (): void {
    $mock = new MockClient([
        IssueObservationGrantRequest::class => MockResponse::make([
            'data' => [
                'observer_url' => 'wss://commander-tasks.herdr.beast.orbit?access_token=orbit-grant-token',
                'scope' => 'terminal.observe',
                'pane' => 'w1:p1',
                'terminal' => 'term-abc',
                'cols' => 120,
                'rows' => 40,
                'expires_at' => '2026-09-13T21:00:00+00:00',
                'nonce' => 'aabbccddeeff00112233445566778899',
            ],
            'meta' => ['request_id' => herdr_request_id()],
        ], 201),
    ]);
    $request = new IssueObservationGrantRequest(12, 'w1:p1', 'term-abc', 120, 40, 'https://tasks.commander.test');
    $response = herdr_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/herdr/sessions/12/observation-grants')
        ->and($request->body()->all())
        ->toBe([
            'pane' => 'w1:p1',
            'terminal' => 'term-abc',
            'cols' => 120,
            'rows' => 40,
            'origin' => 'https://tasks.commander.test',
        ])
        ->and($response)
        ->toBeInstanceOf(ObservationGrantResponse::class)
        ->and($response->observerUrl)
        ->toBe('wss://commander-tasks.herdr.beast.orbit?access_token=orbit-grant-token')
        ->and($response->scope)
        ->toBe('terminal.observe')
        ->and($response->toArray())
        ->not->toHaveKey('token')
        ->and(print_r($response, return: true))
        ->not->toContain('orbit-grant-token');
});

function herdr_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function herdr_session_envelope(): array
{
    return [
        'data' => herdr_session_gateway_data(),
        'meta' => ['request_id' => herdr_request_id()],
    ];
}

/** @return array<string, mixed> */
function herdr_session_gateway_data(): array
{
    return [
        'id' => 12,
        'node' => 'beast',
        'node_id' => 4,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'process_id' => 481,
        'observer_url' => 'wss://commander-tasks.herdr.beast.orbit',
        'status' => 'active',
        'herdr_version' => '0.9.0',
        'protocol' => 22,
        'health' => [
            'process' => 'healthy',
            'listener' => 'healthy',
            'session' => 'healthy',
        ],
        'failed_step' => null,
        'error_code' => null,
    ];
}

function herdr_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
