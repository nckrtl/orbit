<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\RemoveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRegistrationResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead The feature group locks the complete AppInstance request and removal contract. */
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
            ->toBe(instance_request_id())
            ->and($response->hostname)
            ->toBe('orbit-docs.test')
            ->and($response->url)
            ->toBe('https://orbit-docs.test')
            ->and($response->route?->hostname)
            ->toBe('orbit-docs.test');
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

    it('transports an optional Route hostname and preserves omission', function (): void {
        $explicit = new CreateAppInstanceRequest(
            appId: 3,
            nodeId: 4,
            name: 'main',
            hostname: 'Preview.Example.Test',
        );
        $generated = new CreateAppInstanceRequest(appId: 3, nodeId: 4, name: 'main');

        expect($explicit->body()->all())
            ->toBe([
                'app_id' => 3,
                'node_id' => 4,
                'name' => 'main',
                'hostname' => 'Preview.Example.Test',
            ])
            ->and($generated->body()->all())
            ->not->toHaveKey('hostname');
    });

    it('transports an optional explicit branch and preserves omission', function (): void {
        $explicit = new CreateAppInstanceRequest(
            appId: 3,
            nodeId: 4,
            name: 'default',
            branch: 'release',
        );
        $inherited = new CreateAppInstanceRequest(appId: 3, nodeId: 4, name: 'default');

        expect($explicit->body()->all())
            ->toBe([
                'app_id' => 3,
                'node_id' => 4,
                'name' => 'default',
                'branch' => 'release',
            ])
            ->and($inherited->body()->all())
            ->not->toHaveKey('branch');
    });

    it('registers a caller-local source with typed confirmed values', function (): void {
        $registration = [
            'app' => [
                'id' => 3,
                'name' => 'Orbit Docs',
                'slug' => 'orbit-docs',
                'repository_url' => 'https://github.com/nckrtl/orbit-docs.git',
                'default_branch' => 'main',
                'root' => 'public',
                'defaults' => null,
            ],
            'app_instance' => instance_gateway_data(),
            'app_instances' => [instance_gateway_data()],
            'status' => 'active',
            'source_count' => 1,
            'completed_count' => 1,
        ];
        $mockClient = new MockClient([
            RegisterAppInstanceRequest::class => MockResponse::make([
                'data' => $registration,
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $connector = instance_gateway_connector($mockClient);
        $request = new RegisterAppInstanceRequest(
            sourcePath: '/work/orbit-docs',
            includeWorktrees: true,
            appId: 3,
            instanceName: 'preview',
            root: 'web',
        );
        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances/register')
            ->and($request->body()->all())
            ->toBe([
                'source_path' => '/work/orbit-docs',
                'include_worktrees' => true,
                'app_id' => 3,
                'instance_name' => 'preview',
                'root' => 'web',
            ])
            ->and($response)
            ->toBeInstanceOf(AppInstanceRegistrationResponse::class)
            ->and($response->appInstance->sourceLayout)
            ->toBe('checkout')
            ->and($response->sourceCount)
            ->toBe(1)
            ->and($response->requestId)
            ->toBe(instance_request_id());
    });

    it('omits null and false optional registration values', function (): void {
        expect(new RegisterAppInstanceRequest('/work/orbit-docs')->body()->all())
            ->toBe(['source_path' => '/work/orbit-docs']);
    });

    it('transports explicit source profile recovery intent and preserves ordinary omission', function (): void {
        $recover = new CreateAppInstanceRequest(
            appId: 3,
            nodeId: 4,
            name: 'default',
            recoverSourceProfile: true,
        );
        $explicitFalse = new CreateAppInstanceRequest(
            appId: 3,
            nodeId: 4,
            name: 'default',
            recoverSourceProfile: false,
        );
        $ordinary = new CreateAppInstanceRequest(appId: 3, nodeId: 4, name: 'default');

        expect($recover->body()->all())
            ->toBe([
                'app_id' => 3,
                'node_id' => 4,
                'name' => 'default',
                'recover_source_profile' => true,
            ])
            ->and($explicitFalse->body()->all())
            ->toBe([
                'app_id' => 3,
                'node_id' => 4,
                'name' => 'default',
                'recover_source_profile' => false,
            ])
            ->and($ordinary->body()->all())
            ->not->toHaveKey('recover_source_profile');
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
                'app_instances' => [instance_sdk_data()],
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

    it('removes an AppInstance and transports explicit force intent with bounded progress', function (): void {
        $mockClient = new MockClient([
            RemoveAppInstanceRequest::class => MockResponse::make(removal_envelope()),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $remove = new RemoveAppInstanceRequest(7, force: true);
        $response = $connector->send($remove)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($remove->body()->all())
            ->toBe(['force' => true])
            ->and($response)
            ->toBeInstanceOf(AppInstanceRemovalResponse::class)
            ->and($response->toArray())
            ->toBe([...removal_gateway_data(), 'request_id' => instance_request_id()]);
    });

    it('preserves force omission and explicit false', function (): void {
        expect(new RemoveAppInstanceRequest(7)->body()->all())
            ->toBeEmpty()
            ->and(new RemoveAppInstanceRequest(7, force: false)->body()->all())
            ->toBe(['force' => false]);
    });

    it('retains bounded removal progress from a failed accepted request', function (): void {
        $failure = removal_gateway_data();
        $failure['status'] = 'failed';
        $failure['current_step'] = 'runtime_cleanup';
        $failure['completed'] = 0;
        $failure['remaining'] = 1;
        $failure['failed_step'] = 'runtime_cleanup';
        $failure['error_code'] = 'instance.runtime_interrupted';
        $mockClient = new MockClient([
            RemoveAppInstanceRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'instance.runtime_interrupted',
                        'message' => 'AppInstance removal was accepted but remains incomplete.',
                        'details' => ['removal' => $failure],
                        'request_id' => instance_request_id(),
                    ],
                ],
                502,
                ['X-Orbit-Request-Id' => instance_request_id()],
            ),
        ]);
        $connector = instance_gateway_connector($mockClient);

        try {
            $connector->send(new RemoveAppInstanceRequest(7, force: true));
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('instance.runtime_interrupted')
                ->and($exception->requestId())
                ->toBe(instance_request_id())
                ->and($exception->details())
                ->toBe(['removal' => $failure]);
        }
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
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit/apps/orbit-docs',
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'main',
        'branch_override' => null,
        'migration_required' => false,
        'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'detached' => false,
        'status' => 'active',
        'route' => instance_gateway_route_data(),
        'hostname' => 'orbit-docs.test',
        'url' => 'https://orbit-docs.test',
        'removal' => null,
    ];
}

/** @return array<string, mixed> */
function removal_envelope(): array
{
    return [
        'data' => removal_gateway_data(),
        'meta' => ['request_id' => instance_request_id()],
    ];
}

/** @return array<string, mixed> */
function removal_gateway_data(): array
{
    return [
        'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845',
        'id' => 7,
        'name' => 'main',
        'force' => true,
        'status' => 'completed',
        'current_step' => null,
        'total' => 1,
        'completed' => 1,
        'remaining' => 0,
        'failed_step' => null,
        'error_code' => null,
    ];
}

/** @return array<string, mixed> */
function instance_sdk_data(): array
{
    return [
        ...instance_gateway_data(),
        'route' => [
            ...instance_gateway_route_data(),
            'request_id' => instance_request_id(),
        ],
    ];
}

/** @return array<string, mixed> */
function instance_gateway_route_data(): array
{
    return [
        'id' => 9,
        'app_id' => 3,
        'node_id' => 4,
        'cluster_id' => null,
        'generation_basis_node_id' => 4,
        'hostname' => 'orbit-docs.test',
        'provenance' => 'generated',
        'publication' => 'private',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'hostname_change_previous' => null,
        'hostname_change_target' => null,
        'hostname_change_direction' => null,
        'hostname_change_step' => null,
        'target' => ['id' => 10, 'app_instance_id' => 7, 'position' => 0],
    ];
}

function instance_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
