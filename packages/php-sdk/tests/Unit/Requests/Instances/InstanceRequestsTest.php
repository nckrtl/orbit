<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\RemoveInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstancePhpRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('instance requests', function (): void {
    it('creates an instance with stable defaults and maps the typed response', function (): void {
        $mockClient = new MockClient([
            CreateInstanceRequest::class => MockResponse::make(instance_envelope(), 201),
        ]);
        $connector = instance_gateway_connector($mockClient);
        $request = new CreateInstanceRequest(appId: 3, nodeId: 4, name: 'main');

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
                'environment' => 'development',
                'document_root' => 'public',
                'php_version' => '8.5',
            ])
            ->and($response)
            ->toBeInstanceOf(InstanceResponse::class)
            ->and($response->requestId)
            ->toBe(instance_request_id());
    });

    it('lists instances through the explicit collection route', function (): void {
        $mockClient = new MockClient([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_gateway_data()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $response = $connector->send(new ListInstancesRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and($response)
            ->toBeInstanceOf(InstancesResponse::class)
            ->and($response->instances)
            ->toHaveCount(1)
            ->and($response->toArray())
            ->toBe([
                'instances' => [instance_gateway_data()],
                'request_id' => instance_request_id(),
            ]);
    });

    it('shows an instance by numeric ID', function (): void {
        $mockClient = new MockClient([
            ShowInstanceRequest::class => MockResponse::make(instance_envelope()),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $response = $connector->send(new ShowInstanceRequest(7))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($response)
            ->toBeInstanceOf(InstanceResponse::class);
    });

    it('removes an instance and returns its deleted snapshot', function (): void {
        $mockClient = new MockClient([
            RemoveInstanceRequest::class => MockResponse::make(instance_envelope()),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $response = $connector->send(new RemoveInstanceRequest(7))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($response->id)
            ->toBe(7);
    });

    it('changes the PHP version through its explicit action route', function (): void {
        $gatewayData = instance_gateway_data();
        $gatewayData['php_version'] = '8.4';
        $mockClient = new MockClient([
            UpdateInstancePhpRequest::class => MockResponse::make([
                'data' => $gatewayData,
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $connector = instance_gateway_connector($mockClient);
        $request = new UpdateInstancePhpRequest(instanceId: 7, phpVersion: '8.4');

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances/7/php')
            ->and($request->body()->all())
            ->toBe(['php_version' => '8.4'])
            ->and($response)
            ->toBeInstanceOf(InstanceResponse::class)
            ->and($response->phpVersion)
            ->toBe('8.4');
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
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/orbit-docs/main',
        'document_root' => 'public',
        'php_version' => '8.5',
        'hostname' => 'main.beast',
        'certificate_mode' => 'orbit',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
}

function instance_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
