<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Clusters\AddClusterNodeRequest;
use Orbit\Sdk\Requests\Clusters\CreateClusterRequest;
use Orbit\Sdk\Requests\Clusters\DestroyClusterRequest;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Requests\Clusters\RemoveClusterNodeRequest;
use Orbit\Sdk\Requests\Clusters\SetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\UpdateClusterRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('creates a Cluster through the exact typed request and renders JSON', function (): void {
    $mockClient = cluster_cli_mock(CreateClusterRequest::class, cluster_cli_gateway_data(), status: 201);

    $this
        ->artisan('cluster:create', [
            'name' => 'development',
            '--tld' => ' Beast ',
            '--json' => true,
        ])
        ->expectsOutput(cluster_cli_json())
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(CreateClusterRequest::class)
        ->and($request?->body()->all())
        ->toBe([
            'name' => 'development',
            'tld' => ' Beast ',
        ]);
});

it('lists Clusters with deterministic human output', function (): void {
    cluster_cli_mock(ListClustersRequest::class, [cluster_cli_gateway_data()]);

    $this
        ->artisan('cluster:list')
        ->expectsTable(
            ['ID', 'Name', 'TLD', 'State', 'Nodes', 'Router'],
            [[3, 'development', 'beast', 'inactive', 1, '-']],
        )
        ->expectsOutput('Request ID: '.cluster_cli_request_id())
        ->assertExitCode(0);
});

it('shows a Cluster by numeric ID', function (): void {
    $mockClient = cluster_cli_mock(ShowClusterRequest::class, cluster_cli_gateway_data());

    $this
        ->artisan('cluster:show', ['cluster' => '3'])
        ->expectsOutput('development: inactive (#3)')
        ->expectsOutput('TLD: beast')
        ->expectsOutput('Router: -')
        ->expectsOutput('Nodes: app-dev (#2)')
        ->expectsOutput('Request ID: '.cluster_cli_request_id())
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())->toBeInstanceOf(ShowClusterRequest::class);
});

it('updates only supplied Cluster fields and treats an empty TLD as unset', function (): void {
    $mockClient = cluster_cli_mock(UpdateClusterRequest::class, cluster_cli_gateway_data(['tld' => null]));

    $this
        ->artisan('cluster:update', [
            'cluster' => '3',
            '--name' => 'local',
            '--tld' => '',
            '--state' => 'inactive',
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->body()->all())->toBe([
        'name' => 'local',
        'tld' => null,
        'state' => 'inactive',
    ]);
});

it('removes a Cluster only after force confirmation', function (): void {
    $mockClient = cluster_cli_confirmed_mock(DestroyClusterRequest::class, cluster_cli_gateway_data());

    $this
        ->artisan('cluster:destroy', ['cluster' => '3', '--force' => true])
        ->expectsOutput('Cluster [development] removed.')
        ->expectsOutput('Request ID: '.cluster_cli_request_id())
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())->toBeInstanceOf(DestroyClusterRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(2);
});

it('attaches a Node and sets the Router through bodyless PUT requests', function (
    string $command,
    string $requestClass,
    string $endpoint,
): void {
    $mockClient = cluster_cli_mock($requestClass, cluster_cli_gateway_data());

    $this->artisan($command, ['cluster' => '3', 'node' => '2'])->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf($requestClass)
        ->and($request?->resolveEndpoint())
        ->toBe($endpoint);
})->with([
    'attach' => ['cluster:node:add', AddClusterNodeRequest::class, '/api/v1/clusters/3/nodes/2'],
    'Router set' => ['cluster:router:set', SetClusterRouterRequest::class, '/api/v1/clusters/3/router/2'],
]);

it('detaches a Node and clears the Router with confirmed force payloads', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $mockClient = cluster_cli_confirmed_mock($requestClass, cluster_cli_gateway_data());

    $this->artisan($command, [...$arguments, '--force' => true])->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mockClient->getLastRequest()?->body()->all())
        ->toBe(['force' => true])
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(2);
})->with([
    'detach' => ['cluster:node:remove', RemoveClusterNodeRequest::class, ['cluster' => '3', 'node' => '2']],
    'Router clear' => ['cluster:router:unset', UnsetClusterRouterRequest::class, ['cluster' => '3']],
]);

it('rejects invalid IDs before any HTTP request', function (string $command, array $arguments): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain('ID must be a positive integer')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show Cluster' => ['cluster:show', ['cluster' => '0']],
    'update Cluster' => ['cluster:update', ['cluster' => 'not-an-id', '--state' => 'active']],
    'remove Cluster' => ['cluster:destroy', ['cluster' => '-1', '--force' => true]],
    'attach Cluster' => ['cluster:node:add', ['cluster' => '0', 'node' => '2']],
    'attach Node' => ['cluster:node:add', ['cluster' => '3', 'node' => '0']],
    'detach Cluster' => ['cluster:node:remove', ['cluster' => '0', 'node' => '2', '--force' => true]],
    'detach Node' => ['cluster:node:remove', ['cluster' => '3', 'node' => '0', '--force' => true]],
    'set Router Cluster' => ['cluster:router:set', ['cluster' => '0', 'node' => '2']],
    'set Router Node' => ['cluster:router:set', ['cluster' => '3', 'node' => '0']],
    'clear Router' => ['cluster:router:unset', ['cluster' => '0', '--force' => true]],
]);

it('renders only the first invalid ID as one JSON document', function (string $command, array $arguments): void {
    $mockClient = MockClient::global();
    $expected = [
        'error' => [
            'code' => 'cluster.id_invalid',
            'message' => 'Cluster ID must be a positive integer.',
            'request_id' => null,
        ],
    ];

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true, '--no-interaction' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'attach' => ['cluster:node:add', ['cluster' => 'invalid', 'node' => 'invalid']],
    'detach' => ['cluster:node:remove', ['cluster' => 'invalid', 'node' => 'invalid']],
    'set Router' => ['cluster:router:set', ['cluster' => 'invalid', 'node' => 'invalid']],
]);

it('rejects malformed TLD and state values before any HTTP request', function (
    string $command,
    array $arguments,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'new malformed TLD' => [
        'cluster:create',
        ['name' => 'development', '--tld' => 'dev.orbit'],
        'TLD must be one DNS label',
    ],
    'update malformed TLD' => [
        'cluster:update',
        ['cluster' => '3', '--tld' => '-invalid'],
        'TLD must be one DNS label',
    ],
    'update malformed state' => [
        'cluster:update',
        ['cluster' => '3', '--state' => 'pending'],
        'State must be inactive or active',
    ],
]);

it('rejects an empty update before HTTP', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('cluster:update', ['cluster' => '3', '--no-interaction' => true])
        ->expectsOutputToContain('Provide at least one Cluster update option')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('fails as not-found for a missing Cluster without force', function (string $command, array $arguments): void {
    $mockClient = cluster_cli_missing_show_mock();
    $expected = json_encode(cluster_cli_missing_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan($command, [...$arguments, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowClusterRequest::class)
        ->and($mockClient->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/clusters/999999')
        ->and($mockClient->getLastPendingRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'remove' => ['cluster:destroy', ['cluster' => '999999']],
    'detach' => ['cluster:node:remove', ['cluster' => '999999', 'node' => '2']],
    'clear' => ['cluster:router:unset', ['cluster' => '999999']],
]);

it('fails as not-found for a missing Cluster with force', function (string $command, array $arguments): void {
    $mockClient = cluster_cli_missing_show_mock();
    $expected = json_encode(cluster_cli_missing_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan($command, [...$arguments, '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowClusterRequest::class)
        ->and($mockClient->getLastPendingRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'remove' => ['cluster:destroy', ['cluster' => '999999']],
    'detach' => ['cluster:node:remove', ['cluster' => '999999', 'node' => '2']],
    'clear' => ['cluster:router:unset', ['cluster' => '999999']],
]);

it('requires force only after an existing Cluster is resolved', function (
    string $command,
    array $arguments,
    string $operation,
): void {
    $mockClient = cluster_cli_show_mock();
    $expected = json_encode([
        'error' => [
            'code' => 'cluster.confirmation_required',
            'message' => "Use --force to confirm Cluster {$operation}.",
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan($command, [...$arguments, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowClusterRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'remove' => ['cluster:destroy', ['cluster' => '3'], 'removal'],
    'detach' => ['cluster:node:remove', ['cluster' => '3', 'node' => '2'], 'Node detachment'],
    'clear' => ['cluster:router:unset', ['cluster' => '3'], 'Router clearing'],
]);

it('requires human confirmation only after an existing Cluster is resolved', function (
    string $command,
    array $arguments,
): void {
    $mockClient = cluster_cli_show_mock();

    $this
        ->artisan($command, [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain('Use --force')
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowClusterRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
})->with([
    'remove' => ['cluster:destroy', ['cluster' => '3']],
    'detach' => ['cluster:node:remove', ['cluster' => '3', 'node' => '2']],
    'clear' => ['cluster:router:unset', ['cluster' => '3']],
]);

it('accepts explicit confirmation after resolving an existing Cluster', function (
    string $command,
    string $requestClass,
    array $arguments,
    string $operation,
): void {
    $mockClient = cluster_cli_confirmed_mock($requestClass, cluster_cli_gateway_data());

    $this
        ->artisan($command, $arguments)
        ->expectsConfirmation("Confirm Cluster {$operation}?", 'yes')
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(2);
})->with([
    'remove' => ['cluster:destroy', DestroyClusterRequest::class, ['cluster' => '3'], 'removal'],
    'detach' => ['cluster:node:remove', RemoveClusterNodeRequest::class, ['cluster' => '3', 'node' => '2'], 'Node detachment'],
    'clear' => ['cluster:router:unset', UnsetClusterRouterRequest::class, ['cluster' => '3'], 'Router clearing'],
]);

/** @param class-string $requestClass */
function cluster_cli_mock(string $requestClass, mixed $data, int $status = 200): MockClient
{
    return MockClient::global([
        $requestClass => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => cluster_cli_request_id()],
        ], $status),
    ]);
}

/** @param class-string $requestClass */
function cluster_cli_confirmed_mock(string $requestClass, mixed $data, int $status = 200): MockClient
{
    return MockClient::global([
        ShowClusterRequest::class => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => cluster_cli_request_id()],
        ]),
        $requestClass => MockResponse::make([
            'data' => $data,
            'meta' => ['request_id' => cluster_cli_request_id()],
        ], $status),
    ]);
}

function cluster_cli_show_mock(): MockClient
{
    return MockClient::global([
        ShowClusterRequest::class => MockResponse::make([
            'data' => cluster_cli_gateway_data(),
            'meta' => ['request_id' => cluster_cli_request_id()],
        ]),
    ]);
}

function cluster_cli_missing_show_mock(): MockClient
{
    return MockClient::global([
        ShowClusterRequest::class => MockResponse::make(
            cluster_cli_missing_payload(),
            404,
            ['X-Orbit-Request-Id' => cluster_cli_request_id()],
        ),
    ]);
}

/** @return array{error: array{code: string, message: string, details: array<never, never>}} */
function cluster_cli_missing_payload(): array
{
    return [
        'error' => [
            'code' => 'http.404',
            'message' => 'Resource not found.',
            'details' => [],
        ],
    ];
}

/** @return array{error: array{code: string, message: string, request_id: string}} */
function cluster_cli_missing_json(): array
{
    return [
        'error' => [
            'code' => 'http.404',
            'message' => 'Resource not found.',
            'request_id' => cluster_cli_request_id(),
        ],
    ];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cluster_cli_gateway_data(array $overrides = []): array
{
    return array_replace([
        'id' => 3,
        'name' => 'development',
        'tld' => 'beast',
        'state' => 'inactive',
        'nodes' => [[
            'id' => 2,
            'name' => 'app-dev',
            'status' => 'active',
            'wireguard_ip' => '10.44.0.2',
            'lan_ip' => '10.0.0.2',
        ]],
        'router' => null,
    ], $overrides);
}

function cluster_cli_json(): string
{
    return json_encode([
        ...cluster_cli_gateway_data(),
        'request_id' => cluster_cli_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function cluster_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
