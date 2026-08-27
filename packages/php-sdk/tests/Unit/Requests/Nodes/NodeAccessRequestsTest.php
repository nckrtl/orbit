<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('keeps constructor order as consumer then serving while the add endpoint stays serving then consumer', function (): void {
    $mockClient = new MockClient([
        AddNodeAccessRequest::class => MockResponse::make([
            'data' => added_node_access_gateway_data(),
            'meta' => ['request_id' => node_access_request_id()],
        ]),
    ]);
    $connector = node_access_gateway_connector($mockClient);
    $request = new AddNodeAccessRequest(consumerNodeId: 2, servingNodeId: 3);

    $response = $connector->send($request)->dto();
    $pendingRequest = $mockClient->getLastPendingRequest();

    expect($request->getMethod())
        ->toBe(Method::PUT)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes/3/access/2')
        ->and($pendingRequest?->body())
        ->toBeNull()
        ->and($response)
        ->toBeInstanceOf(AddedNodeAccessResponse::class)
        ->and($response->requestId)
        ->toBe(node_access_request_id())
        ->and($response->toArray())
        ->toBe([
            'consumer_node' => ['id' => 2, 'name' => 'consumer'],
            'serving_node' => ['id' => 3, 'name' => 'serving'],
            'already_exists' => false,
            'request_id' => node_access_request_id(),
        ]);
});

it('keeps constructor order as consumer then serving while the remove endpoint stays serving then consumer', function (): void {
    $mockClient = new MockClient([
        RemoveNodeAccessRequest::class => MockResponse::make([
            'data' => removed_node_access_gateway_data(),
            'meta' => ['request_id' => node_access_request_id()],
        ]),
    ]);
    $connector = node_access_gateway_connector($mockClient);
    $request = new RemoveNodeAccessRequest(consumerNodeId: 2, servingNodeId: 3);

    $response = $connector->send($request)->dto();
    $pendingRequest = $mockClient->getLastPendingRequest();

    expect($request->getMethod())
        ->toBe(Method::DELETE)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes/3/access/2')
        ->and($pendingRequest?->body())
        ->toBeNull()
        ->and($response)
        ->toBeInstanceOf(RemovedNodeAccessResponse::class)
        ->and($response->requestId)
        ->toBe(node_access_request_id())
        ->and($response->toArray())
        ->toBe([
            'consumer_node' => ['id' => 2, 'name' => 'consumer'],
            'serving_node' => ['id' => 3, 'name' => 'serving'],
            'already_absent' => false,
            'self_lockout' => true,
            'request_id' => node_access_request_id(),
        ]);
});

it('rejects malformed nested node summaries instead of inventing a fake node', function (): void {
    assert_node_access_boundary_exception(
        fn (): AddedNodeAccessResponse => AddedNodeAccessResponse::fromGatewayData([
            'consumer_node' => ['id' => 0, 'name' => ''],
            'serving_node' => ['id' => 3, 'name' => 'serving'],
            'already_exists' => true,
        ], node_access_request_id()),
        message: 'Gateway response contains an invalid consumer_node summary.',
    );
    assert_node_access_boundary_exception(
        fn (): AddedNodeAccessResponse => AddedNodeAccessResponse::fromGatewayData([
            'consumer_node' => ['id' => 2, 'name' => 'consumer'],
            'serving_node' => ['id' => '3', 'name' => 'serving'],
            'already_exists' => true,
        ], node_access_request_id()),
        message: 'Gateway response contains an invalid serving_node summary.',
    );
    assert_node_access_boundary_exception(
        fn (): RemovedNodeAccessResponse => RemovedNodeAccessResponse::fromGatewayData([
            'consumer_node' => ['id' => 0, 'name' => ''],
            'serving_node' => ['id' => 3, 'name' => 'serving'],
            'already_absent' => false,
            'self_lockout' => true,
        ], node_access_request_id()),
        message: 'Gateway response contains an invalid consumer_node summary.',
    );
    assert_node_access_boundary_exception(
        fn (): RemovedNodeAccessResponse => RemovedNodeAccessResponse::fromGatewayData([
            'consumer_node' => ['id' => 2, 'name' => 'consumer'],
            'serving_node' => ['id' => '3', 'name' => 'serving'],
            'already_absent' => false,
            'self_lockout' => true,
        ], node_access_request_id()),
        message: 'Gateway response contains an invalid serving_node summary.',
    );

    expect(NodeAccessNodeResponse::tryFromGatewayData(['id' => 7, 'name' => 'worker'])?->toArray())
        ->toBe(['id' => 7, 'name' => 'worker'])
        ->and(NodeAccessNodeResponse::tryFromGatewayData(['id' => 0, 'name' => 'worker']))
        ->toBeNull()
        ->and(NodeAccessNodeResponse::tryFromGatewayData(['id' => 7, 'name' => '']))
        ->toBeNull()
        ->and(NodeAccessNodeResponse::tryFromGatewayData(['id' => '7', 'name' => 'worker']))
        ->toBeNull();
});

it('maps a real 403 node access error into a GatewayApiException with safe details and header request id', function (): void {
    $mockClient = new MockClient([
        AddNodeAccessRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node_access.required',
                    'message' => 'Node access is required.',
                    'details' => [
                        'consumer_node' => ['id' => 2, 'name' => 'consumer'],
                        'serving_node' => ['id' => 3, 'name' => 'serving'],
                    ],
                ],
            ],
            403,
            ['X-Orbit-Request-Id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
        ),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    try {
        $connector->send(new AddNodeAccessRequest(consumerNodeId: 2, servingNodeId: 3))->dto();
        $this->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        expect($exception->getMessage())
            ->toBe('Node access is required.')
            ->and($exception->errorCode())
            ->toBe('node_access.required')
            ->and($exception->details())
            ->toBe([
                'consumer_node' => ['id' => 2, 'name' => 'consumer'],
                'serving_node' => ['id' => 3, 'name' => 'serving'],
            ])
            ->and($exception->requestId())
            ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
    }
});

function node_access_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector(
        'https://10.44.0.1',
        requestIdResolver: static fn (): string => '11111111-1111-4111-8111-111111111111',
    );
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function added_node_access_gateway_data(): array
{
    return [
        'consumer_node' => ['id' => 2, 'name' => 'consumer'],
        'serving_node' => ['id' => 3, 'name' => 'serving'],
        'already_exists' => false,
    ];
}

/** @return array<string, mixed> */
function removed_node_access_gateway_data(): array
{
    return [
        'consumer_node' => ['id' => 2, 'name' => 'consumer'],
        'serving_node' => ['id' => 3, 'name' => 'serving'],
        'already_absent' => false,
        'self_lockout' => true,
    ];
}

function node_access_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function assert_node_access_boundary_exception(callable $callback, string $message): void
{
    try {
        $callback();
        test()->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        expect($exception->getMessage())
            ->toBe($message)
            ->and($exception->requestId())
            ->toBe(node_access_request_id());
    }
}
