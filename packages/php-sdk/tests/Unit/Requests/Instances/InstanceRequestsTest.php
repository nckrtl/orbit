<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\AppInstances\RemoveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('AppInstance requests', function (): void {
    it('creates an AppInstance with inherited root and maps the typed response', function (): void {
        $mockClient = new MockClient([
            CreateAppInstanceRequest::class => MockResponse::make(instance_envelope(), 201),
        ]);
        $connector = instance_gateway_connector($mockClient);
        $request = new CreateAppInstanceRequest(appId: 3, nodeId: 4, name: 'main');

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and($request->body()->all())
            ->toBe([
                'app_id' => 3,
                'node_id' => 4,
                'name' => 'main',
            ])
            ->and($response)
            ->toBeInstanceOf(AppInstanceResponse::class)
            ->and($response->requestId)
            ->toBe(instance_request_id());
    });

    it('transports only the optional root override', function (): void {
        $request = new CreateAppInstanceRequest(
            appId: 3,
            nodeId: 4,
            name: 'main',
            root: 'site/public',
        );

        expect($request->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'root' => 'site/public',
        ]);
    });

    it('lists instances through the explicit collection route', function (): void {
        $mockClient = new MockClient([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [instance_gateway_data()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $response = $connector->send(new ListAppInstancesRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and($response)
            ->toBeInstanceOf(AppInstancesResponse::class)
            ->and($response->appInstances)
            ->toHaveCount(1)
            ->and($response->toArray())
            ->toBe([
                'app_instances' => [instance_gateway_data()],
                'request_id' => instance_request_id(),
            ]);
    });

    it('shows an instance by numeric ID', function (): void {
        $mockClient = new MockClient([
            ShowAppInstanceRequest::class => MockResponse::make(instance_envelope()),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $response = $connector->send(new ShowAppInstanceRequest(7))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($response)
            ->toBeInstanceOf(AppInstanceResponse::class);
    });

    it('removes an AppInstance and transports explicit discard intent', function (): void {
        $mockClient = new MockClient([
            RemoveAppInstanceRequest::class => MockResponse::make(instance_envelope()),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $remove = new RemoveAppInstanceRequest(7, discardSource: true);
        $response = $connector->send($remove)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($remove->body()->all())
            ->toBe(['discard_source' => true])
            ->and($response->id)
            ->toBe(7);
    });
});

function instance_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function instance_envelope(): array
{
    return [
        'data' => instance_gateway_data(),
        'meta' => ['request_id' => instance_request_id()],
    ];
}

/** @return array<string, mixed> */
function instance_gateway_data(): array
{
    return [
        'id' => 7,
        'app_id' => 3,
        'node_id' => 4,
        'cluster_id' => 2,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/orbit-docs',
        'root' => null,
        'effective_root' => 'public',
        'branch' => 'main',
        'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'status' => 'active',
    ];
}

function instance_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
