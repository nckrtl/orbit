<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Routes\ClearRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\ListRoutesRequest;
use Orbit\Sdk\Requests\Routes\RemoveRouteRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Orbit\Sdk\Responses\Routes\RoutesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('transports explicit Route creation values without applying Gateway policy', function (): void {
    $request = new CreateRouteRequest(3, 'Odd_Value', 'future-policy', appInstanceId: 7);

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/routes')
        ->and($request->body()->all())
        ->toBe([
            'app_id' => 3,
            'hostname' => 'Odd_Value',
            'publication' => 'future-policy',
            'app_instance_id' => 7,
        ]);
});

it('preserves targetless Node and Cluster scope transport', function (): void {
    expect(new CreateRouteRequest(3, 'node.test', 'private', nodeId: 4)->body()->all())
        ->toHaveKey('node_id', 4)
        ->not->toHaveKey('cluster_id')->and(
            new CreateRouteRequest(3, 'cluster.test', 'public', clusterId: 5)->body()->all(),
        )->toHaveKey('cluster_id', 5)
        ->not->toHaveKey('node_id');
});

it('maps bounded Route responses and list responses', function (): void {
    $mock = new MockClient([
        ShowRouteRequest::class => MockResponse::make(route_envelope()),
        ListRoutesRequest::class => MockResponse::make([
            'data' => [route_data()],
            'meta' => ['request_id' => route_request_id()],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    $shown = $connector->send(new ShowRouteRequest(11))->dto();
    $listed = $connector->send(new ListRoutesRequest)->dto();

    expect($shown)
        ->toBeInstanceOf(RouteResponse::class)
        ->and($shown->toArray())
        ->toBe([...route_data(), 'request_id' => route_request_id()])
        ->and($listed)
        ->toBeInstanceOf(RoutesResponse::class)
        ->and($listed->routes)
        ->toHaveCount(1);
});

it('defines the exact update, target, clear, and remove transports', function (): void {
    $update = new UpdateRouteRequest(11, hostname: 'next.test', publication: 'public');
    $set = new SetRouteTargetRequest(11, 8);
    $clear = new ClearRouteTargetRequest(11);
    $remove = new RemoveRouteRequest(11);

    expect($update->getMethod())
        ->toBe(Method::PATCH)
        ->and($update->resolveEndpoint())
        ->toBe('/api/v1/routes/11')
        ->and($update->body()->all())
        ->toBe(['hostname' => 'next.test', 'publication' => 'public'])
        ->and($set->getMethod())
        ->toBe(Method::PUT)
        ->and($set->resolveEndpoint())
        ->toBe('/api/v1/routes/11/target')
        ->and($set->body()->all())
        ->toBe(['app_instance_id' => 8])
        ->and($clear->getMethod())
        ->toBe(Method::DELETE)
        ->and($clear->resolveEndpoint())
        ->toBe('/api/v1/routes/11/target')
        ->and($remove->getMethod())
        ->toBe(Method::DELETE)
        ->and($remove->resolveEndpoint())
        ->toBe('/api/v1/routes/11');
});

/** @return array<string, mixed> */
function route_envelope(): array
{
    return ['data' => route_data(), 'meta' => ['request_id' => route_request_id()]];
}

/** @return array<string, mixed> */
function route_data(): array
{
    return [
        'id' => 11,
        'app_id' => 3,
        'node_id' => 4,
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'hostname' => 'app.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
        'failed_step' => null,
        'error_code' => null,
        'target' => ['id' => 12, 'app_instance_id' => 7, 'position' => 0],
    ];
}

function route_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
