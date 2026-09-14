<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Clusters\AddClusterNodeRequest;
use Orbit\Sdk\Requests\Clusters\CreateClusterRequest;
use Orbit\Sdk\Requests\Clusters\DestroyClusterRequest;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Requests\Clusters\RemoveClusterNodeRequest;
use Orbit\Sdk\Requests\Clusters\SetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\UpdateClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Orbit\Sdk\Responses\Clusters\ClustersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('Cluster requests', function (): void {
    it('lists Clusters through the typed collection route', function (): void {
        [$connector, $mockClient] = cluster_connector(ListClustersRequest::class, [cluster_request_gateway_data()]);

        $response = $connector->send(new ListClustersRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/clusters')
            ->and($response)
            ->toBeInstanceOf(ClustersResponse::class)
            ->and($response->clusters)
            ->toHaveCount(1)
            ->and($response->requestId)
            ->toBe(cluster_requests_request_id());
    });

    it('creates a Cluster and omits an absent TLD', function (): void {
        [$connector] = cluster_connector(
            CreateClusterRequest::class,
            cluster_request_gateway_data(),
            status: 201,
        );
        $request = new CreateClusterRequest(name: 'development');

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/clusters')
            ->and($request->body()->all())
            ->toBe(['name' => 'development'])
            ->and($response)
            ->toBeInstanceOf(ClusterResponse::class);
    });

    it('creates a Cluster with the exact TLD value supplied', function (): void {
        $request = new CreateClusterRequest(name: 'development', tld: 'Beast');

        expect($request->body()->all())->toBe([
            'name' => 'development',
            'tld' => 'Beast',
        ]);
    });

    it('shows and removes a Cluster by numeric ID', function (): void {
        $show = new ShowClusterRequest(3);
        $remove = new DestroyClusterRequest(3);

        expect($show->getMethod())
            ->toBe(Method::GET)
            ->and($show->resolveEndpoint())
            ->toBe('/api/v1/clusters/3')
            ->and($remove->getMethod())
            ->toBe(Method::DELETE)
            ->and($remove->resolveEndpoint())
            ->toBe('/api/v1/clusters/3');
    });

    it('updates only supplied fields and preserves explicit null', function (): void {
        $request = new UpdateClusterRequest(
            clusterId: 3,
            hasName: true,
            name: 'development',
            hasTld: true,
            tld: null,
        );

        expect($request->getMethod())
            ->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/clusters/3')
            ->and($request->body()->all())
            ->toBe([
                'name' => 'development',
                'tld' => null,
            ]);
    });

    it('transports Cluster state without enforcing policy', function (): void {
        $request = new UpdateClusterRequest(
            clusterId: 3,
            hasState: true,
            state: 'future-state',
        );

        expect($request->body()->all())->toBe(['state' => 'future-state']);
    });

    it('attaches and sets a Router through bodyless PUT requests', function (): void {
        $attach = new AddClusterNodeRequest(clusterId: 3, nodeId: 7);
        $setRouter = new SetClusterRouterRequest(clusterId: 3, nodeId: 7);

        expect($attach->getMethod())
            ->toBe(Method::PUT)
            ->and($attach->resolveEndpoint())
            ->toBe('/api/v1/clusters/3/nodes/7')
            ->and($setRouter->getMethod())
            ->toBe(Method::PUT)
            ->and($setRouter->resolveEndpoint())
            ->toBe('/api/v1/clusters/3/router/7');
    });

    it('detaches and clears a Router with the required force payload', function (): void {
        $detach = new RemoveClusterNodeRequest(clusterId: 3, nodeId: 7, force: true);
        $clearRouter = new UnsetClusterRouterRequest(clusterId: 3, force: true);

        expect($detach->getMethod())
            ->toBe(Method::DELETE)
            ->and($detach->resolveEndpoint())
            ->toBe('/api/v1/clusters/3/nodes/7')
            ->and($detach->body()->all())
            ->toBe(['force' => true])
            ->and($clearRouter->getMethod())
            ->toBe(Method::DELETE)
            ->and($clearRouter->resolveEndpoint())
            ->toBe('/api/v1/clusters/3/router')
            ->and($clearRouter->body()->all())
            ->toBe(['force' => true]);
    });

    it('maps every single-resource mutation to the typed response', function (string $requestClass): void {
        [$connector] = cluster_connector($requestClass, cluster_request_gateway_data());
        $request = match ($requestClass) {
            ShowClusterRequest::class => new ShowClusterRequest(3),
            UpdateClusterRequest::class => new UpdateClusterRequest(3, hasName: true, name: 'development'),
            DestroyClusterRequest::class => new DestroyClusterRequest(3),
            AddClusterNodeRequest::class => new AddClusterNodeRequest(3, 7),
            RemoveClusterNodeRequest::class => new RemoveClusterNodeRequest(3, 7, true),
            SetClusterRouterRequest::class => new SetClusterRouterRequest(3, 7),
            UnsetClusterRouterRequest::class => new UnsetClusterRouterRequest(3, true),
        };

        expect($connector->send($request)->dto())->toBeInstanceOf(ClusterResponse::class);
    })->with([
        ShowClusterRequest::class,
        UpdateClusterRequest::class,
        DestroyClusterRequest::class,
        AddClusterNodeRequest::class,
        RemoveClusterNodeRequest::class,
        SetClusterRouterRequest::class,
        UnsetClusterRouterRequest::class,
    ]);

    it('does not keep replaced Cluster request class names', function (string $class): void {
        expect(class_exists($class))->toBeFalse();
    })->with([
        'Orbit\\Sdk\\Requests\\Clusters\\RemoveClusterRequest',
        'Orbit\\Sdk\\Requests\\Clusters\\AttachClusterNodeRequest',
        'Orbit\\Sdk\\Requests\\Clusters\\DetachClusterNodeRequest',
        'Orbit\\Sdk\\Requests\\Clusters\\ClearClusterRouterRequest',
    ]);
});

/**
 * @param  class-string  $requestClass
 * @return array{GatewayConnector, MockClient}
 */
function cluster_connector(string $requestClass, mixed $data, int $status = 200): array
{
    $mockClient = new MockClient([
        $requestClass => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => cluster_requests_request_id()],
        ], $status),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    return [$connector, $mockClient];
}

/** @return array<string, mixed> */
function cluster_request_gateway_data(): array
{
    return [
        'id' => 3,
        'name' => 'development',
        'tld' => 'beast',
        'state' => 'inactive',
        'nodes' => [],
        'router' => null,
    ];
}

function cluster_requests_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
