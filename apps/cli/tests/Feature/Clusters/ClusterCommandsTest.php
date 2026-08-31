<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Clusters\AttachClusterNodeRequest;
use Orbit\Sdk\Requests\Clusters\ClearClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\CreateClusterRequest;
use Orbit\Sdk\Requests\Clusters\DetachClusterNodeRequest;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Requests\Clusters\RemoveClusterRequest;
use Orbit\Sdk\Requests\Clusters\SetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Requests\Clusters\UpdateClusterRequest;
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
        ->artisan('cluster:new', [
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
    $mockClient = cluster_cli_mock(RemoveClusterRequest::class, cluster_cli_gateway_data());

    $this
        ->artisan('cluster:remove', ['cluster' => '3', '--force' => true])
        ->expectsOutput('Cluster [development] removed.')
        ->expectsOutput('Request ID: '.cluster_cli_request_id())
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())->toBeInstanceOf(RemoveClusterRequest::class);
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
    'attach' => ['cluster:node:attach', AttachClusterNodeRequest::class, '/api/v1/clusters/3/nodes/2'],
    'Router set' => ['cluster:router:set', SetClusterRouterRequest::class, '/api/v1/clusters/3/router/2'],
]);

it('detaches a Node and clears the Router with confirmed force payloads', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $mockClient = cluster_cli_mock($requestClass, cluster_cli_gateway_data());

    $this->artisan($command, [...$arguments, '--force' => true])->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mockClient->getLastRequest()?->body()->all())
        ->toBe(['force' => true]);
})->with([
    'detach' => ['cluster:node:detach', DetachClusterNodeRequest::class, ['cluster' => '3', 'node' => '2']],
    'Router clear' => ['cluster:router:clear', ClearClusterRouterRequest::class, ['cluster' => '3']],
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
    'remove Cluster' => ['cluster:remove', ['cluster' => '-1', '--force' => true]],
    'attach Cluster' => ['cluster:node:attach', ['cluster' => '0', 'node' => '2']],
    'attach Node' => ['cluster:node:attach', ['cluster' => '3', 'node' => '0']],
    'detach Cluster' => ['cluster:node:detach', ['cluster' => '0', 'node' => '2', '--force' => true]],
    'detach Node' => ['cluster:node:detach', ['cluster' => '3', 'node' => '0', '--force' => true]],
    'set Router Cluster' => ['cluster:router:set', ['cluster' => '0', 'node' => '2']],
    'set Router Node' => ['cluster:router:set', ['cluster' => '3', 'node' => '0']],
    'clear Router' => ['cluster:router:clear', ['cluster' => '0', '--force' => true]],
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
        'cluster:new',
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

it('rejects an empty update and destructive commands without confirmation before HTTP', function (
    string $command,
    array $arguments,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, [...$arguments, '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'empty update' => ['cluster:update', ['cluster' => '3'], 'Provide at least one Cluster update option'],
    'remove confirmation' => ['cluster:remove', ['cluster' => '3'], 'Use --force'],
    'detach confirmation' => ['cluster:node:detach', ['cluster' => '3', 'node' => '2'], 'Use --force'],
    'clear confirmation' => ['cluster:router:clear', ['cluster' => '3'], 'Use --force'],
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
