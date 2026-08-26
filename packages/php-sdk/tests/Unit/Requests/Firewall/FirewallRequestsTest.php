<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\DenyFirewallRuleRequest;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('maps allow and deny requests to fixed named-rule payloads', function (
    string $requestClass,
    string $action,
): void {
    $request = new $requestClass(
        nodeId: 7,
        name: 'private-web',
        source: '192.0.2.0/24',
        protocol: 'tcp',
        port: '443',
    );
    $mock = new MockClient([
        $request::class => MockResponse::make(firewall_sdk_envelope($action), 201),
    ]);
    $response = firewall_sdk_connector($mock)->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe("/api/v1/nodes/7/firewall-rules/{$action}")
        ->and($request->body()->all())
        ->toBe([
            'name' => 'private-web',
            'source' => '192.0.2.0/24',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->and($response)
        ->toBeInstanceOf(FirewallRuleResponse::class)
        ->and($response->nodeId)
        ->toBe(7)
        ->and($response->node)
        ->toBe('app-dev')
        ->and($response->name)
        ->toBe('private-web')
        ->and($response->action)
        ->toBe($action)
        ->and($response->backendStatus)
        ->toBe('active')
        ->and($response->requestId)
        ->toBe(firewall_sdk_request_id())
        ->and($response->toArray())
        ->toMatchArray([
            'name' => 'private-web',
            'action' => $action,
            'source' => '192.0.2.0/24',
            'protocol' => 'tcp',
            'port' => '443',
            'backend_status' => 'active',
            'request_id' => firewall_sdk_request_id(),
        ])
        ->and($mock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe('11111111-1111-4111-8111-111111111111');
})->with([
    'allow' => [AllowFirewallRuleRequest::class, 'allow'],
    'deny' => [DenyFirewallRuleRequest::class, 'deny'],
]);

it('omits firewall fields that the Gateway defaults', function (): void {
    $request = new AllowFirewallRuleRequest(
        nodeId: 7,
        name: 'private-web',
        source: null,
        protocol: null,
        port: '443',
    );

    expect($request->body()->all())->toBe([
        'name' => 'private-web',
        'port' => '443',
    ]);
});

it('lists and removes stable names within one node', function (): void {
    $list = new ListFirewallRulesRequest(7);
    $listMock = new MockClient([
        ListFirewallRulesRequest::class => MockResponse::make([
            'data' => [firewall_sdk_data('allow', backendStatus: null)],
            'meta' => ['request_id' => firewall_sdk_request_id()],
        ]),
    ]);
    $listResponse = firewall_sdk_connector($listMock)->send($list)->dto();

    expect($list->getMethod())
        ->toBe(Method::GET)
        ->and($list->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/firewall-rules')
        ->and($listResponse)
        ->toBeInstanceOf(FirewallRulesResponse::class)
        ->and($listResponse->rules)
        ->toHaveCount(1)
        ->and($listResponse->rules[0]->name)
        ->toBe('private-web')
        ->and($listResponse->rules[0]->backendStatus)
        ->toBeNull()
        ->and($listResponse->requestId)
        ->toBe(firewall_sdk_request_id())
        ->and($listMock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe('11111111-1111-4111-8111-111111111111');

    $remove = new RemoveFirewallRuleRequest(7, 'private web/edge');
    $removeMock = new MockClient([
        RemoveFirewallRuleRequest::class => MockResponse::make(firewall_sdk_envelope('allow', backendStatus: 'absent')),
    ]);
    $removeResponse = firewall_sdk_connector($removeMock)->send($remove)->dto();

    expect($remove->getMethod())
        ->toBe(Method::DELETE)
        ->and($remove->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/firewall-rules/private%20web%2Fedge')
        ->and($removeResponse)
        ->toBeInstanceOf(FirewallRuleResponse::class)
        ->and($removeResponse->name)
        ->toBe('private-web')
        ->and($removeResponse->action)
        ->toBe('allow')
        ->and($removeResponse->backendStatus)
        ->toBe('absent')
        ->and($removeResponse->requestId)
        ->toBe(firewall_sdk_request_id())
        ->and($removeMock->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe('11111111-1111-4111-8111-111111111111');
});

it('throws a structured 503 failure when the firewall backend is inactive', function (
    string $requestClass,
): void {
    $request = $requestClass === RemoveFirewallRuleRequest::class
        ? new RemoveFirewallRuleRequest(7, 'private-web')
        : new $requestClass(
            nodeId: 7,
            name: 'private-web',
            source: '192.0.2.0/24',
            protocol: 'tcp',
            port: '443',
        );
    $mock = new MockClient([
        $request::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'firewall.backend_inactive',
                    'message' => 'UFW is inactive on node [app-dev].',
                    'details' => ['step' => 'status'],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => firewall_sdk_request_id()],
        ),
    ]);

    try {
        firewall_sdk_connector($mock)->send($request)->dto();
        $this->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())
            ->toBe('firewall.backend_inactive')
            ->and($exception->getMessage())
            ->toBe('UFW is inactive on node [app-dev].')
            ->and($exception->details())
            ->toBe(['step' => 'status'])
            ->and($exception->requestId())
            ->toBe(firewall_sdk_request_id());
    }
})->with([
    'allow' => [AllowFirewallRuleRequest::class],
    'deny' => [DenyFirewallRuleRequest::class],
    'remove' => [RemoveFirewallRuleRequest::class],
]);

function firewall_sdk_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector(
        'https://10.44.0.1',
        requestIdResolver: static fn (): string => '11111111-1111-4111-8111-111111111111',
    );
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function firewall_sdk_envelope(string $action, ?string $backendStatus = 'active'): array
{
    return [
        'data' => firewall_sdk_data($action, $backendStatus),
        'meta' => ['request_id' => firewall_sdk_request_id()],
    ];
}

/** @return array<string, mixed> */
function firewall_sdk_data(string $action, ?string $backendStatus = 'active'): array
{
    $data = [
        'id' => 11,
        'node_id' => 7,
        'node' => 'app-dev',
        'name' => 'private-web',
        'action' => $action,
        'source' => '192.0.2.0/24',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];

    if ($backendStatus !== null) {
        $data['backend_status'] = $backendStatus;
    }

    return $data;
}

function firewall_sdk_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
