<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Apps\RemoveAppRequest;
use Orbit\Sdk\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('app requests', function (): void {
    it('creates an app with SDK defaults and maps the typed response', function (): void {
        $mockClient = new MockClient([
            CreateAppRequest::class => MockResponse::make([
                'data' => app_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ], 201),
        ]);
        $connector = app_gateway_connector($mockClient);
        $request = new CreateAppRequest(
            slug: 'orbit-docs',
            repositoryUrl: 'git@github.com:nckrtl/orbit-docs.git',
        );

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/apps')
            ->and($request->body()->all())
            ->toBe([
                'name' => 'orbit-docs',
                'slug' => 'orbit-docs',
                'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
                'defaults' => null,
            ])
            ->and($response)
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->requestId)
            ->toBe(orbit_request_id());
    });

    it('lists apps through the explicit collection route', function (): void {
        $mockClient = new MockClient([
            ListAppsRequest::class => MockResponse::make([
                'data' => [app_gateway_data()],
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);

        $response = $connector->send(new ListAppsRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/apps')
            ->and($response)
            ->toBeInstanceOf(AppsResponse::class)
            ->and($response->apps)
            ->toHaveCount(1)
            ->and($response->apps[0])
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->toArray())
            ->toBe([
                'apps' => [app_public_data()],
                'request_id' => orbit_request_id(),
            ]);
    });

    it('shows an app by numeric ID', function (): void {
        $mockClient = new MockClient([
            ShowAppRequest::class => MockResponse::make([
                'data' => app_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);

        $response = $connector->send(new ShowAppRequest(3))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/apps/3')
            ->and($response)
            ->toBeInstanceOf(AppResponse::class);
    });

    it('removes an app by numeric ID and returns its deleted snapshot', function (): void {
        $mockClient = new MockClient([
            RemoveAppRequest::class => MockResponse::make([
                'data' => app_gateway_data(),
                'meta' => ['request_id' => orbit_request_id()],
            ]),
        ]);
        $connector = app_gateway_connector($mockClient);

        $response = $connector->send(new RemoveAppRequest(3))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/apps/3')
            ->and($response)
            ->toBeInstanceOf(AppResponse::class)
            ->and($response->id)
            ->toBe(3);
    });
});

function app_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function app_gateway_data(): array
{
    return [
        'id' => 3,
        'name' => 'orbit-docs',
        'slug' => 'orbit-docs',
        'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
        'defaults' => null,
    ];
}

/** @return array<string, mixed> */
function app_public_data(): array
{
    return app_gateway_data();
}

function orbit_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
