<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceRegistrationResponse;
use Orbit\Sdk\Responses\Instances\InstanceRemovalResponse;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('Instance rename requests', function (): void {
    it('transports optional branch and domain and maps the Instance response', function (?string $branch, ?string $domain, array $body): void {
        $request = new RenameInstanceRequest(3, $branch, $domain);
        $response = instance_gateway_connector(new MockClient([
            RenameInstanceRequest::class => MockResponse::make(instance_envelope()),
        ]))->send($request)->dto();
        expect($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/instances/3/rename')
            ->and($request->body()->all())->toBe($body)
            ->and($response)->toBeInstanceOf(InstanceResponse::class)
            ->and($response->requestId)->toBe(instance_request_id());
    })->with([
        'branch only' => ['t3code/login', null, ['branch' => 't3code/login']],
        'domain only' => [null, 'Login.Example.Test', ['domain' => 'Login.Example.Test']],
        'both' => ['t3code/login', 'login.example.test', ['branch' => 't3code/login', 'domain' => 'login.example.test']],
        'preserves empty input for Gateway validation' => ['', '', ['branch' => '', 'domain' => '']],
    ]);
});

describe('Instance requests', function (): void {
    it('creates an Instance with inherited root and maps the typed response', function (): void {
        $mockClient = new MockClient([
            CreateInstanceRequest::class => MockResponse::make(instance_envelope(), 201),
        ]);
        $connector = instance_gateway_connector($mockClient);
        $request = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'main');

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and($request->body()->all())
            ->toBe([
                'project_id' => 3,
                'node_id' => 4,
                'name' => 'main',
            ])
            ->and($response)
            ->toBeInstanceOf(InstanceResponse::class)
            ->and($response->requestId)
            ->toBe(instance_request_id())
            ->and($response->domain)
            ->toBe('orbit-docs.test')
            ->and($response->url)
            ->toBe('https://orbit-docs.test')
            ->and($response->route?->domain)
            ->toBe('orbit-docs.test');
    });

    it('maps recorded production placement identity', function (): void {
        $data = [
            ...instance_gateway_data(),
            'environment' => 'production',
            'checkout_path' => '/home/orbit-app-3',
            'production_user' => 'orbit-app-3',
            'production_home' => '/home/orbit-app-3',
            'effective_root' => '/home/orbit-app-3/current/public',
        ];
        $mockClient = new MockClient([
            CreateInstanceRequest::class => MockResponse::make([
                'data' => $data,
                'meta' => ['request_id' => instance_request_id()],
            ], 201),
        ]);
        $response = instance_gateway_connector($mockClient)
            ->send(new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'main'))
            ->dto();

        expect($response->productionUser)
            ->toBe('orbit-app-3')
            ->and($response->productionHome)
            ->toBe('/home/orbit-app-3')
            ->and($response->effectiveRoot)
            ->toBe('/home/orbit-app-3/current/public');
    });

    it('transports only the optional root override', function (): void {
        $request = new CreateInstanceRequest(
            projectId: 3,
            nodeId: 4,
            name: 'main',
            root: 'site/public',
        );

        expect($request->body()->all())->toBe([
            'project_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'root' => 'site/public',
        ]);
    });

    it('transports an optional Route domain and preserves omission', function (): void {
        $explicit = new CreateInstanceRequest(
            projectId: 3,
            nodeId: 4,
            name: 'main',
            domain: 'Preview.Example.Test',
        );
        $generated = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'main');

        expect($explicit->body()->all())
            ->toBe([
                'project_id' => 3,
                'node_id' => 4,
                'name' => 'main',
                'domain' => 'Preview.Example.Test',
            ])
            ->and($explicit->body()->all())
            ->not->toHaveKey('hostname')
            ->and($generated->body()->all())
            ->not->toHaveKey('domain')
            ->not->toHaveKey('hostname');
    });

    it('transports an optional registration Route domain and preserves omission', function (): void {
        $explicit = new RegisterInstanceRequest(
            sourcePath: '/work/orbit-docs',
            domain: 'Preview.Example.Test',
        );

        expect($explicit->body()->all())
            ->toBe([
                'source_path' => '/work/orbit-docs',
                'domain' => 'Preview.Example.Test',
            ])
            ->and($explicit->body()->all())
            ->not->toHaveKey('hostname')
            ->and(new RegisterInstanceRequest('/work/orbit-docs')->body()->all())
            ->not->toHaveKey('domain')
            ->not->toHaveKey('hostname');
    });

    it('does not treat leftover hostname fields as Instance domain aliases', function (): void {
        $payload = instance_gateway_data();
        unset($payload['domain']);
        $payload['hostname'] = 'alias.test';
        $route = $payload['route'] ?? [];

        if (! is_array($route)) {
            $this->fail('Expected a Route payload.');
        }

        unset($route['domain']);
        $route['hostname'] = 'alias.test';
        $payload['route'] = $route;

        $response = InstanceResponse::fromGatewayData($payload, instance_request_id());

        expect($response->domain)
            ->toBeNull()
            ->and($response->route?->domain)
            ->toBe('')
            ->and($response->toArray())
            ->not->toHaveKey('hostname')
            ->and($response->route?->toArray() ?? [])
            ->not->toHaveKey('hostname');
    });

    it('transports an optional explicit branch and preserves omission', function (): void {
        $explicit = new CreateInstanceRequest(
            projectId: 3,
            nodeId: 4,
            name: 'default',
            branch: 'release',
        );
        $inherited = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'default');

        expect($explicit->body()->all())
            ->toBe([
                'project_id' => 3,
                'node_id' => 4,
                'name' => 'default',
                'branch' => 'release',
            ])
            ->and($inherited->body()->all())
            ->not->toHaveKey('branch');
    });

    it('registers a caller-local source with typed confirmed values', function (): void {
        $registration = [
            'project' => [
                'id' => 3,
                'name' => 'Orbit Docs',
                'slug' => 'orbit-docs',
                'repository_url' => 'https://github.com/nckrtl/orbit-docs.git',
                'default_branch' => 'main',
                'root' => 'public',
            ],
            'instance' => instance_gateway_data(),
            'instances' => [instance_gateway_data()],
            'status' => 'active',
            'source_count' => 1,
            'completed_count' => 1,
        ];
        $mockClient = new MockClient([
            RegisterInstanceRequest::class => MockResponse::make([
                'data' => $registration,
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $connector = instance_gateway_connector($mockClient);
        $request = new RegisterInstanceRequest(
            sourcePath: '/work/orbit-docs',
            includeWorktrees: true,
            projectId: 3,
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
                'project_id' => 3,
                'instance_name' => 'preview',
                'root' => 'web',
            ])
            ->and($response)
            ->toBeInstanceOf(InstanceRegistrationResponse::class)
            ->and($response->instance->sourceLayout)
            ->toBe('checkout')
            ->and($response->sourceCount)
            ->toBe(1)
            ->and($response->requestId)
            ->toBe(instance_request_id());
    });

    it('omits null and false optional registration values', function (): void {
        expect(new RegisterInstanceRequest('/work/orbit-docs')->body()->all())
            ->toBe(['source_path' => '/work/orbit-docs']);
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
                'instances' => [instance_sdk_data()],
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

    it('removes an Instance and transports explicit force intent with bounded progress', function (): void {
        $mockClient = new MockClient([
            DestroyInstanceRequest::class => MockResponse::make(removal_envelope()),
        ]);
        $connector = instance_gateway_connector($mockClient);

        $remove = new DestroyInstanceRequest(7, force: true);
        $response = $connector->send($remove)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($remove->body()->all())
            ->toBe(['force' => true])
            ->and($response)
            ->toBeInstanceOf(InstanceRemovalResponse::class)
            ->and($response->toArray())
            ->toBe([...removal_gateway_data(), 'request_id' => instance_request_id()]);
    });

    it('preserves force omission and explicit false', function (): void {
        expect(new DestroyInstanceRequest(7)->body()->all())
            ->toBeEmpty()
            ->and(new DestroyInstanceRequest(7, force: false)->body()->all())
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
            DestroyInstanceRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'instance.runtime_interrupted',
                        'message' => 'Instance removal was accepted but remains incomplete.',
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
            $connector->send(new DestroyInstanceRequest(7, force: true));
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
        'project_id' => 3,
        'node_id' => 4,
        'vite_port' => null,
        'name' => 'main',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit/apps/orbit-docs',
        'production_user' => null,
        'production_home' => null,
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'main',
        'branch_override' => null,
        'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'seed_path' => null,
        'seed_commit' => null,
        'detached' => false,
        'status' => 'active',
        'route' => instance_gateway_route_data(),
        'domain' => 'orbit-docs.test',
        'url' => 'https://orbit-docs.test',
        'removal' => null,
        'transfer' => null,
        'deploy_steps' => [],
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
    $data = instance_gateway_data();
    $withIdentities = [];
    foreach ($data as $key => $value) {
        $withIdentities[$key] = $value;
        if ($key === 'node_id') {
            // The SDK carries the Project and Node identities the Gateway names beside an instance.
            $withIdentities['project'] = null;
            $withIdentities['node'] = null;
        }
    }

    return [
        ...$withIdentities,
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
        'kind' => 'app',
        'project_id' => 3,
        'node_id' => 4,
        'cluster_id' => null,
        'generation_basis_node_id' => 4,
        'domain' => 'orbit-docs.test',
        'provenance' => 'generated',
        'publication' => 'private',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'replaces_route_id' => null,
        'replaced_by_route_id' => null,
        'replacement_step' => null,
        'target_set_step' => null,
        'target' => ['id' => 10, 'instance_id' => 7, 'position' => 0],
        'targets' => [['id' => 10, 'instance_id' => 7, 'position' => 0]],
        'process_id' => null,
        'upstream' => null,
    ];
}

function instance_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
